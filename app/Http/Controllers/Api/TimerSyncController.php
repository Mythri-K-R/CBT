<?php

namespace App\Http\Controllers\Api;

use App\Events\ExamHeartbeatReceived;
use App\Http\Controllers\Controller;
use App\Models\TestAttempt;
use App\Services\ExamEngine\TimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimerSyncController extends Controller
{
    public function __construct(private readonly TimerService $timer) {}

    public function sync(Request $request, string $attemptUuid): JsonResponse
    {
        // timerState is no longer eager-loaded here: TimerService reads from Redis
        // on the hot path and falls back to a direct DB query only on cache miss.
        $attempt = TestAttempt::where('uuid', $attemptUuid)
                              ->where('status', 'in_progress')
                              ->firstOrFail();

        if ($attempt->isExpired()) {
            return response()->json([
                'remaining_seconds' => 0,
                'expired'           => true,
                'server_time'       => now()->toISOString(),
            ]);
        }

        $remaining = $this->timer->sync($attempt);

        // Fire heartbeat event for monitoring hooks (no default listeners at 667/s).
        event(new ExamHeartbeatReceived($attempt->id, $remaining['remaining']));

        return response()->json([
            'remaining_seconds' => $remaining['remaining'],
            'section_timers'    => $remaining['sections'],
            'server_time'       => now()->toISOString(),
            'expired'           => false,
        ]);
    }
}
