<?php

namespace App\Http\Controllers\Api;

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
        $attempt = TestAttempt::where('uuid', $attemptUuid)
                              ->where('status', 'in_progress')
                              ->with('timerState')
                              ->firstOrFail();

        if ($attempt->isExpired()) {
            return response()->json([
                'remaining_seconds' => 0,
                'expired'           => true,
                'server_time'       => now()->toISOString(),
            ]);
        }

        $remaining = $this->timer->sync($attempt);

        return response()->json([
            'remaining_seconds' => $remaining['remaining'],
            'section_timers'    => $remaining['sections'],
            'server_time'       => now()->toISOString(),
            'expired'           => false,
        ]);
    }
}
