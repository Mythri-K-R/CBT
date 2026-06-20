<?php

namespace App\Http\Middleware;

use App\Events\SessionConflictDetected;
use App\Models\TestAttempt;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SingleSessionLock
{
    public function handle(Request $request, Closure $next): mixed
    {
        $attemptUuid = $request->route('attemptUuid');

        if (!$attemptUuid) {
            return $next($request);
        }

        $attempt = TestAttempt::where('uuid', $attemptUuid)->first();

        if (!$attempt || $attempt->status !== 'in_progress') {
            return response()->json(['message' => 'Invalid or completed attempt.'], 403);
        }

        $sessionToken = $request->header('X-Exam-Session-Token');

        if (!$sessionToken) {
            return response()->json(['message' => 'Missing exam session token.'], 401);
        }

        $redisKey    = "exam:session:{$attempt->id}";
        $storedToken = Redis::connection('default')->get($redisKey);

        if ($storedToken && $storedToken !== $sessionToken) {
            // Another browser/tab has taken over — record conflict.
            // Wrapped in try/catch: a DB failure here must not produce a 500 on
            // the student's save/submit/timer request. The conflict is already
            // handled by returning 409; the log entry is best-effort.
            try {
                \App\Models\ProctorEvent::create([
                    'attempt_id' => $attempt->id,
                    'event_type' => 'session_conflict',
                    'details'    => ['existing_session' => substr($storedToken, 0, 8).'...'],
                    'ip_address' => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                Log::error('SingleSessionLock: failed to persist session conflict event', [
                    'attempt_id' => $attempt->id,
                    'ip'         => $request->ip(),
                    'error'      => $e->getMessage(),
                ]);
            }

            event(new SessionConflictDetected(
                $attempt,
                $request->ip(),
                substr($storedToken, 0, 8) . '...',
            ));

            return response()->json(['message' => 'Another session is active for this attempt.'], 409);
        }

        // Refresh token TTL (keeps session alive while the student is active)
        $ttl = config('examsphere.exam.session_token_ttl_minutes', 5) * 60;
        Redis::connection('default')->setex($redisKey, $ttl, $sessionToken);

        return $next($request);
    }
}
