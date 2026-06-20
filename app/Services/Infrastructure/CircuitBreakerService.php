<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker Service — Feature 7 Failure Recovery
 *
 * Prevents cascading failures by detecting when a dependency (Redis, MySQL,
 * Queue) is unhealthy and fast-failing subsequent calls instead of waiting
 * for timeouts.
 *
 * ── States ────────────────────────────────────────────────────────────────────
 *   CLOSED    Normal. Requests pass through. Failures are counted.
 *   OPEN      Dependency is down. Requests fail immediately without attempting
 *             the call. After reset_timeout seconds, transitions to HALF_OPEN.
 *   HALF_OPEN One probe request is allowed. Success → CLOSED. Failure → OPEN.
 *
 * ── Storage Strategy ─────────────────────────────────────────────────────────
 *   Layer 1 — static class property: in-process, zero-latency, per FPM worker.
 *             Lives for the lifetime of the worker (500+ requests). Guaranteed
 *             to always be available.
 *   Layer 2 — APCu shared memory: when php-apcu extension is installed,
 *             state is shared across all workers on the same server.
 *             Workers converge to the same circuit state within milliseconds.
 *   Layer 3 — Redis: when the circuit is NOT for Redis itself, state is also
 *             written to Redis so all servers in a multi-server cluster share
 *             the same circuit state.
 *
 * ── The Redis Circuit's Special Case ─────────────────────────────────────────
 *   The Redis circuit breaker NEVER uses Redis for its own state — doing so
 *   would require Redis to be available to detect that Redis is unavailable.
 *   It uses only Layer 1 (static) + Layer 2 (APCu). Each server's workers
 *   independently detect Redis failure, which is correct: if Redis is down,
 *   ALL workers on ALL servers will fail and open their circuits independently
 *   within failure_threshold requests.
 *
 * ── Backward Compatibility ───────────────────────────────────────────────────
 *   All existing services catch \Throwable and degrade gracefully. This service
 *   ENHANCES them by skipping the actual call when the circuit is OPEN,
 *   reducing Redis/MySQL connection-attempt overhead from O(N requests) to O(1)
 *   per reset_timeout window. Injected as optional constructor dependency —
 *   if not injected (e.g. in tests), services continue to work without it.
 */
class CircuitBreakerService
{
    const STATE_CLOSED    = 'closed';
    const STATE_OPEN      = 'open';
    const STATE_HALF_OPEN = 'half_open';

    // Persists across requests within the same FPM worker lifecycle.
    private static array $inProcess = [];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Execute $operation protected by the circuit breaker for $service.
     *
     * @param string   $service    'redis' | 'mysql' | 'queue'
     * @param callable $operation  The dependency call to protect
     * @param mixed    $fallback   Value (or Closure) returned when circuit is OPEN.
     *                             If null and circuit is OPEN, throws CircuitOpenException.
     * @return mixed
     * @throws \Throwable when $operation throws and no fallback given, or when
     *                    circuit is OPEN and $fallback is null.
     */
    public function call(string $service, callable $operation, mixed $fallback = null): mixed
    {
        $state = $this->getState($service);

        // ── OPEN: fast fail or attempt probe ──────────────────────────────────
        if ($state === self::STATE_OPEN) {
            if (!$this->canAttemptReset($service)) {
                // Still within the reset_timeout window — skip the call entirely.
                if ($fallback !== null) {
                    return value($fallback);
                }
                throw new \RuntimeException(
                    "CircuitBreaker[{$service}]: circuit is OPEN — dependency unavailable",
                    503
                );
            }
            // Reset timeout elapsed — allow one probe in HALF_OPEN.
            $this->transitionTo($service, self::STATE_HALF_OPEN);
        }

        // ── CLOSED / HALF_OPEN: attempt the call ──────────────────────────────
        try {
            $result = $operation();
            $this->onSuccess($service);
            return $result;
        } catch (\Throwable $e) {
            $this->onFailure($service, $e);
            if ($fallback !== null) {
                return value($fallback);
            }
            throw $e;
        }
    }

    /**
     * Check if a service's circuit is currently OPEN.
     * Callers can use this for conditional logic without a try/catch.
     */
    public function isOpen(string $service): bool
    {
        return $this->getState($service) === self::STATE_OPEN;
    }

    /**
     * Manually force a circuit to CLOSED (e.g. after ops confirms service is back).
     */
    public function reset(string $service): void
    {
        $this->transitionTo($service, self::STATE_CLOSED);
        Log::info("CircuitBreaker[{$service}]: manually reset to CLOSED");
    }

    /**
     * Explicitly record a successful dependency call.
     * Used by services that manage their own try/catch but still want circuit tracking.
     */
    public function recordSuccess(string $service): void
    {
        $this->onSuccess($service);
    }

    /**
     * Explicitly record a failed dependency call.
     * Used by services that manage their own try/catch but still want circuit tracking.
     */
    public function recordFailure(string $service, \Throwable $e): void
    {
        $this->onFailure($service, $e);
    }

    /**
     * Return all known circuit states for the monitoring endpoint.
     *
     * @return array<string, array{state: string, failures: int, opened_at: int|null}>
     */
    public function getStates(): array
    {
        $services = array_keys(config('resilience.circuit_breakers', []));
        $result   = [];

        foreach ($services as $service) {
            $data   = $this->readStateData($service);
            $result[$service] = [
                'state'     => $data['state']      ?? self::STATE_CLOSED,
                'failures'  => $data['failures']   ?? 0,
                'opened_at' => $data['opened_at']  ?? null,
                'threshold' => config("resilience.circuit_breakers.{$service}.failure_threshold"),
            ];
        }

        return $result;
    }

    // ── State transitions ─────────────────────────────────────────────────────

    private function onSuccess(string $service): void
    {
        $data    = $this->readStateData($service);
        $state   = $data['state'] ?? self::STATE_CLOSED;
        $cfg     = $this->config($service);

        if ($state === self::STATE_HALF_OPEN) {
            $successes = ($data['successes'] ?? 0) + 1;
            if ($successes >= $cfg['success_threshold']) {
                $this->transitionTo($service, self::STATE_CLOSED);
                Log::info("CircuitBreaker[{$service}]: probe succeeded — circuit CLOSED");
            } else {
                $this->writeStateData($service, array_merge($data, ['successes' => $successes]));
            }
        } elseif ($state === self::STATE_CLOSED) {
            // Reset failure counter on success in CLOSED state.
            if (($data['failures'] ?? 0) > 0) {
                $this->writeStateData($service, $this->initialState());
            }
        }
    }

    private function onFailure(string $service, \Throwable $e): void
    {
        $data     = $this->readStateData($service);
        $state    = $data['state'] ?? self::STATE_CLOSED;
        $cfg      = $this->config($service);
        $failures = ($data['failures'] ?? 0) + 1;

        Log::warning("CircuitBreaker[{$service}]: failure #{$failures} recorded", [
            'error' => $e->getMessage(),
            'state' => $state,
        ]);

        if ($state === self::STATE_HALF_OPEN || $failures >= $cfg['failure_threshold']) {
            $this->transitionTo($service, self::STATE_OPEN);
            Log::error("CircuitBreaker[{$service}]: circuit OPENED after {$failures} failure(s)");
            $this->logRecoveryEvent($service, 'circuit_open', ['failures' => $failures]);
        } else {
            $this->writeStateData($service, array_merge($data, [
                'state'    => self::STATE_CLOSED,
                'failures' => $failures,
            ]));
        }
    }

    private function transitionTo(string $service, string $newState): void
    {
        $now     = now()->timestamp;
        $current = $this->readStateData($service);

        $data = [
            'state'      => $newState,
            'failures'   => $newState === self::STATE_CLOSED ? 0 : ($current['failures'] ?? 0),
            'successes'  => 0,
            'opened_at'  => $newState === self::STATE_OPEN ? $now : null,
            'changed_at' => $now,
        ];

        $this->writeStateData($service, $data);

        if ($newState === self::STATE_CLOSED && ($current['state'] ?? '') !== self::STATE_CLOSED) {
            $this->logRecoveryEvent($service, 'circuit_closed', []);
        }
    }

    private function canAttemptReset(string $service): bool
    {
        $data     = $this->readStateData($service);
        $openedAt = $data['opened_at'] ?? null;

        if (!$openedAt) {
            return true;
        }

        $cfg = $this->config($service);
        return (now()->timestamp - $openedAt) >= $cfg['reset_timeout'];
    }

    private function getState(string $service): string
    {
        return $this->readStateData($service)['state'] ?? self::STATE_CLOSED;
    }

    // ── State storage ─────────────────────────────────────────────────────────

    /**
     * Read circuit state from fastest available storage.
     * Priority: static > APCu > Redis (for non-redis circuit) > default CLOSED.
     */
    private function readStateData(string $service): array
    {
        // Layer 1: in-process static (always fastest, zero I/O)
        if (isset(static::$inProcess[$service])) {
            return static::$inProcess[$service];
        }

        // Layer 2: APCu shared memory (cross-worker, same server)
        if ($this->apcuAvailable()) {
            $cached = apcu_fetch("cb_{$service}", $success);
            if ($success && is_array($cached)) {
                static::$inProcess[$service] = $cached;
                return $cached;
            }
        }

        // Layer 3: Redis (cross-server, non-Redis circuits only)
        if ($service !== 'redis' && $this->getStorageBackend($service) === 'redis') {
            try {
                $cached = Cache::get("cb:state:{$service}");
                if (is_array($cached)) {
                    static::$inProcess[$service] = $cached;
                    return $cached;
                }
            } catch (\Throwable) {
                // Redis unavailable — fall through to initial state
            }
        }

        return $this->initialState();
    }

    /**
     * Write circuit state to all available storage layers.
     */
    private function writeStateData(string $service, array $data): void
    {
        // Layer 1: always update in-process
        static::$inProcess[$service] = $data;

        // Layer 2: APCu if available
        if ($this->apcuAvailable()) {
            apcu_store("cb_{$service}", $data, 600); // 10 min APCu TTL
        }

        // Layer 3: Redis (non-redis circuits only)
        if ($service !== 'redis' && $this->getStorageBackend($service) === 'redis') {
            try {
                Cache::put("cb:state:{$service}", $data, 600);
            } catch (\Throwable) {
                // Redis unavailable — in-process + APCu is sufficient
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function config(string $service): array
    {
        return config("resilience.circuit_breakers.{$service}", [
            'failure_threshold' => 5,
            'success_threshold' => 2,
            'reset_timeout'     => 30,
            'storage'           => 'local',
        ]);
    }

    private function getStorageBackend(string $service): string
    {
        return $this->config($service)['storage'] ?? 'local';
    }

    private function initialState(): array
    {
        return [
            'state'      => self::STATE_CLOSED,
            'failures'   => 0,
            'successes'  => 0,
            'opened_at'  => null,
            'changed_at' => null,
        ];
    }

    private function apcuAvailable(): bool
    {
        return function_exists('apcu_fetch') && apcu_enabled();
    }

    private function logRecoveryEvent(string $service, string $eventType, array $meta): void
    {
        try {
            \Illuminate\Support\Facades\DB::table('exam_recovery_log')->insert([
                'event_type' => $eventType,
                'service'    => $service,
                'metadata'   => json_encode($meta),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // If DB is down we cannot log — that's fine; the event is already in the Laravel log
        }
    }
}
