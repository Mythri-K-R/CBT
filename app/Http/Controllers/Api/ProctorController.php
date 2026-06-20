<?php

namespace App\Http\Controllers\Api;

use App\Events\FullscreenExitDetected;
use App\Events\SuspiciousActivityDetected;
use App\Events\TabSwitchDetected;
use App\Http\Controllers\Controller;
use App\Models\ProctorEvent;
use App\Models\TestAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProctorController extends Controller
{
    private const VIOLATION_TYPES = ['tab_switch', 'fullscreen_exit', 'copy_attempt', 'right_click'];

    public function logEvent(Request $request, string $attemptUuid): JsonResponse
    {
        $request->validate([
            'event_type' => 'required|in:tab_switch,fullscreen_exit,fullscreen_enter,copy_attempt,right_click,window_resize,idle_detected,screenshot_attempt,session_conflict',
            'details'    => 'nullable|array',
        ]);

        $attempt = TestAttempt::where('uuid', $attemptUuid)
                              ->where('status', 'in_progress')
                              ->firstOrFail();

        // Persist the raw proctoring event. If the DB is under pressure and this
        // write fails, log the event to the file system and continue — the student
        // must never receive a 500 error because a security log write failed.
        try {
            ProctorEvent::create([
                'attempt_id' => $attempt->id,
                'event_type' => $request->event_type,
                'details'    => $request->details,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ProctorController: failed to persist proctoring event', [
                'attempt_uuid' => $attemptUuid,
                'event_type'   => $request->event_type,
                'ip'           => $request->ip(),
                'error'        => $e->getMessage(),
            ]);
        }

        // Increment violation counters on attempt
        if ($request->event_type === 'tab_switch') {
            $attempt->increment('tab_switch_count');
            $attempt->refresh();

            event(new TabSwitchDetected($attempt, $attempt->tab_switch_count));

            $limit = $attempt->test->tab_switch_limit;
            if ($limit > 0 && $attempt->tab_switch_count >= $limit) {
                event(new SuspiciousActivityDetected($attempt, 'tab_switch', $attempt->tab_switch_count));

                if ($attempt->test->auto_submit_on_violation) {
                    dispatch(function () use ($attempt) {
                        app(\App\Services\ExamEngine\TestSubmitService::class)
                            ->submit($attempt, 'auto_violation');
                    });
                }

                return response()->json([
                    'warning'    => 'You have exceeded the tab switch limit.',
                    'auto_submit'=> $attempt->test->auto_submit_on_violation,
                ]);
            }
        }

        if ($request->event_type === 'fullscreen_exit') {
            $attempt->increment('fullscreen_violations');
            $attempt->refresh();

            event(new FullscreenExitDetected($attempt, $attempt->fullscreen_violations));
        }

        return response()->json(['success' => true]);
    }
}
