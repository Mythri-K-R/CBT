<?php

namespace App\Http\Middleware;

use App\Models\TestAttempt;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        $cacheKey = "exam_session_{$attempt->id}";
        $storedToken = Cache::get($cacheKey);

        if ($storedToken && $storedToken !== $sessionToken) {
            // Another browser/tab has taken over — record conflict
            \App\Models\ProctorEvent::create([
                'attempt_id' => $attempt->id,
                'event_type' => 'session_conflict',
                'details'    => ['existing_session' => substr($storedToken, 0, 8).'...'],
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['message' => 'Another session is active for this attempt.'], 409);
        }

        // Refresh token TTL (keeps session alive while the student is active)
        Cache::put($cacheKey, $sessionToken, now()->addMinutes(config('examsphere.exam.session_token_ttl_minutes', 5)));

        return $next($request);
    }
}
