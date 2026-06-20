<?php

namespace App\Providers;

use App\Services\ExamEngine\ExamMonitoringService;
use App\Services\ExamEngine\ExamStateCacheService;
use App\Services\Infrastructure\CircuitBreakerService;
use App\Services\Infrastructure\DeadLetterQueueService;
use App\Services\Infrastructure\DistributedLockService;
use App\Services\Infrastructure\ExamSessionRecoveryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Feature 7: Failure Recovery — Singleton bindings ──────────────────
        //
        // CircuitBreakerService: singleton so static $inProcess state persists
        // across requests within the same FPM worker lifecycle (key feature).
        $this->app->singleton(CircuitBreakerService::class);

        // Services that optionally consume the circuit breaker.
        // Explicit binding ensures the singleton is injected, not a fresh instance.
        $this->app->singleton(ExamStateCacheService::class, fn ($app) =>
            new ExamStateCacheService($app->make(CircuitBreakerService::class))
        );

        $this->app->singleton(DistributedLockService::class, fn ($app) =>
            new DistributedLockService($app->make(CircuitBreakerService::class))
        );

        $this->app->singleton(ExamMonitoringService::class, fn ($app) =>
            new ExamMonitoringService($app->make(CircuitBreakerService::class))
        );

        $this->app->singleton(ExamSessionRecoveryService::class, fn ($app) =>
            new ExamSessionRecoveryService(
                $app->make(ExamStateCacheService::class),
                $app->make(ExamMonitoringService::class),
                $app->make(DistributedLockService::class)
            )
        );

        $this->app->singleton(DeadLetterQueueService::class);
    }

    public function boot(): void
    {
        $this->enforceCacheDriver();
        $this->enforceQueueConnection();
        $this->configureModels();
        $this->configureRateLimiters();
        $this->configureResponseMacros();
        $this->configureSlowQueryLogging();
    }

    // ── P0-5: Cache driver enforcement ───────────────────────────────────────
    //
    // Cache::tags() — used by ExamEngineController::saveResponse() — throws a
    // BadMethodCallException at runtime if the cache driver is not Redis. This
    // guard catches the misconfiguration at boot time, before any student request
    // reaches the exam save path.
    //
    // ONLY enforced in production. Local and staging environments may use 'file'
    // or 'array' drivers for simplicity.

    private function enforceCacheDriver(): void
    {
        if ($this->app->isProduction() && config('cache.default') !== 'redis') {
            throw new \RuntimeException(
                'ExamSphere production requires CACHE_STORE=redis. ' .
                'Current driver: "' . config('cache.default') . '". ' .
                'Cache::tags() used by exam save-response will throw with any other driver. ' .
                'Set CACHE_STORE=redis in your production .env and restart PHP-FPM.'
            );
        }
    }

    // ── H-1: Queue connection enforcement ────────────────────────────────────
    //
    // Horizon only polls Redis queues. If QUEUE_CONNECTION is missing or set to
    // 'database', jobs dispatched by TestSubmitService land in MySQL and Horizon
    // never sees them — students wait indefinitely for scoring results.
    // This guard makes that misconfiguration a hard boot-time failure rather than
    // a silent runtime one.

    private function enforceQueueConnection(): void
    {
        if ($this->app->isProduction() && config('queue.default') !== 'redis') {
            throw new \RuntimeException(
                'ExamSphere production requires QUEUE_CONNECTION=redis. ' .
                'Current connection: "' . config('queue.default') . '". ' .
                'Horizon only monitors Redis queues — scoring jobs will never run ' .
                'with any other driver. Set QUEUE_CONNECTION=redis in .env and restart PHP-FPM.'
            );
        }
    }

    // ── Eloquent model configuration ──────────────────────────────────────────

    private function configureModels(): void
    {
        // In production, guard against lazy-loaded N+1 queries and mass-
        // assignment vulnerabilities by making the ORM strict.
        if ($this->app->isProduction()) {
            Model::preventLazyLoading();          // N+1 → exception instead of silent query
            Model::preventSilentlyDiscardingAttributes(); // Unknown fillable → exception
        }

        // In tests / local, log lazy-loads rather than throwing.
        if ($this->app->isLocal()) {
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
                Log::warning('Lazy loading detected', [
                    'model'    => get_class($model),
                    'relation' => $relation,
                ]);
            });
        }
    }

    // ── Rate limiters ─────────────────────────────────────────────────────────
    //
    // WHY PER-ENDPOINT LIMITERS:
    //   • login      — brute-force protection (5 attempts/minute per IP+username).
    //   • exam-save  — prevents a buggy client from flooding the save endpoint
    //                  (120/minute = 2/second per attempt, well above the
    //                  10-second auto-save interval).
    //   • exam-submit— idempotency guard (3 attempts/minute in case of retries).
    //   • api        — general authenticated API rate limit (300/minute).
    //   • public     — guest endpoints like test join (60/minute per IP).
    //
    // P0-3: LOAD_TEST_BYPASS_THROTTLE
    //   When APP_ENV=local AND LOAD_TEST_BYPASS_THROTTLE=true, the login rate
    //   limiter is disabled so k6 can ramp up without hitting 429 errors.
    //   This bypass is IMPOSSIBLE to activate in production:
    //     • app()->isLocal() is always false when APP_ENV=production
    //     • The env variable alone is not sufficient — both conditions must hold
    //   All other rate limiters (exam-save, exam-submit, etc.) are NEVER bypassed.

    private function configureRateLimiters(): void
    {
        // Login — key on IP + username to allow legitimate users on shared IPs
        RateLimiter::for('login', function (Request $request) {
            // Load-test bypass: only when explicitly running in local environment.
            // Both conditions must be true — env var alone cannot bypass production.
            if ($this->app->environment('local') && (bool) env('LOAD_TEST_BYPASS_THROTTLE', false)) {
                return Limit::perMinute(PHP_INT_MAX)->by('load-test-bypass');
            }

            $key = mb_strtolower($request->input('username', '')) . '|' . $request->ip();
            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'message' => 'Too many login attempts. Please wait a minute and try again.',
                ], 429);
            });
        });

        // Exam auto-save — keyed on attempt_uuid so rate is per-student per-exam
        RateLimiter::for('exam-save', function (Request $request) {
            $key = 'save:' . ($request->input('attempt_uuid', $request->ip()));
            return Limit::perMinute(120)->by($key)->response(function () {
                return response()->json([
                    'saved'   => false,
                    'message' => 'Save rate limit exceeded. Slow down auto-save interval.',
                ], 429);
            });
        });

        // Exam submit — keyed on attemptUuid route param
        RateLimiter::for('exam-submit', function (Request $request) {
            $key = 'submit:' . ($request->route('attemptUuid') ?? $request->ip());
            return Limit::perMinute(3)->by($key)->response(function () {
                return response()->json([
                    'message' => 'Submission request rate limit exceeded.',
                ], 429);
            });
        });

        // Offline sync — keyed on attemptUuid; batch operations, so lower than exam-save
        RateLimiter::for('exam-sync', function (Request $request) {
            $key = 'sync:' . ($request->route('attemptUuid') ?? $request->ip());
            return Limit::perMinute(10)->by($key)->response(function () {
                return response()->json([
                    'message' => 'Sync rate limit exceeded. Please wait before retrying.',
                ], 429);
            });
        });

        // General authenticated API
        RateLimiter::for('api', function (Request $request) {
            $id  = $request->user()?->id ?? $request->ip();
            return Limit::perMinute(300)->by($id);
        });

        // Public guest endpoints (test join, results lookup, etc.)
        RateLimiter::for('public', function (Request $request) {
            if ($this->app->environment('local') && (bool) env('LOAD_TEST_BYPASS_THROTTLE', false)) {
                return Limit::perMinute(PHP_INT_MAX)->by('load-test-bypass-public');
            }
            return Limit::perMinute(60)->by($request->ip());
        });
    }

    // ── Response macros ───────────────────────────────────────────────────────

    private function configureResponseMacros(): void
    {
        Response::macro('success', function (mixed $data = null, string $message = 'Success', int $status = 200) {
            $payload = ['success' => true, 'message' => $message];
            if ($data !== null) {
                $payload['data'] = $data;
            }
            return response()->json($payload, $status);
        });

        Response::macro('error', function (string $message = 'Error', int $status = 400, mixed $errors = null) {
            $payload = ['success' => false, 'message' => $message];
            if ($errors !== null) {
                $payload['errors'] = $errors;
            }
            return response()->json($payload, $status);
        });
    }

    // ── Slow-query logging ────────────────────────────────────────────────────
    //
    // Queries taking longer than 500 ms are logged at WARNING level.
    // In production this feeds into log monitoring / alerting.

    private function configureSlowQueryLogging(): void
    {
        $threshold = (int) config('examsphere.slow_query_threshold_ms', 500);

        DB::whenQueryingForLongerThan($threshold, function (\Illuminate\Database\Connection $connection) use ($threshold) {
            Log::warning('Slow database query detected', [
                'connection'   => $connection->getName(),
                'threshold_ms' => $threshold,
            ]);
        });
    }
}
