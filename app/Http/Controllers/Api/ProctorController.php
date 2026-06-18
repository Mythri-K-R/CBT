<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProctorEvent;
use App\Models\TestAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        ProctorEvent::create([
            'attempt_id' => $attempt->id,
            'event_type' => $request->event_type,
            'details'    => $request->details,
            'ip_address' => $request->ip(),
        ]);

        // Increment violation counters on attempt
        if ($request->event_type === 'tab_switch') {
            $attempt->increment('tab_switch_count');

            $limit = $attempt->test->tab_switch_limit;
            if ($limit > 0 && $attempt->tab_switch_count >= $limit) {
                if ($attempt->test->auto_submit_on_violation) {
                    // Trigger auto-submit via job
                    \App\Jobs\ProcessQuestionImport::dispatch()->onQueue('high');
                    // Actually dispatch auto-submit:
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
        }

        return response()->json(['success' => true]);
    }
}
