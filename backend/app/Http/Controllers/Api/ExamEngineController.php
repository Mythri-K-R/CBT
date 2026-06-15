<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TestAttempt;
use App\Models\TestLink;
use App\Services\ExamEngine\TestStartService;
use App\Services\ExamEngine\TestSubmitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamEngineController extends Controller
{
    public function __construct(
        private readonly TestStartService  $startService,
        private readonly TestSubmitService $submitService
    ) {}

    public function start(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'access_code'=> 'nullable|string',
        ]);

        $link = TestLink::where('slug', $slug)->firstOrFail();

        if (!$link->isValid()) {
            return response()->json(['message' => 'Test link is inactive or expired.'], 410);
        }

        // Validate access code
        if ($link->access_code && $request->access_code !== $link->access_code) {
            return response()->json(['message' => 'Invalid access code.'], 403);
        }

        $result = $this->startService->start(
            $link,
            $request->student_id,
            $request->ip(),
            $request->userAgent()
        );

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        $link->increment('total_starts');

        return response()->json([
            'success'       => true,
            'attempt_uuid'  => $result['attempt']->uuid,
            'session_token' => $result['session_token'],
            'server_end_at' => $result['attempt']->server_end_time,
            'questions'     => $result['questions'],
        ]);
    }

    public function saveResponse(Request $request): JsonResponse
    {
        $request->validate([
            'attempt_uuid'      => 'required|string',
            'test_question_id'  => 'required|integer',
            'selected_answer'   => 'nullable|string',
            'status'            => 'required|in:not_answered,answered,marked_review,answered_marked_review',
            'time_spent_seconds'=> 'nullable|integer|min:0',
        ]);

        $attempt = TestAttempt::where('uuid', $request->attempt_uuid)
                              ->where('status', 'in_progress')
                              ->firstOrFail();

        if ($attempt->isExpired()) {
            return response()->json(['message' => 'Time expired.'], 422);
        }

        $response = $attempt->responses()
                             ->where('test_question_id', $request->test_question_id)
                             ->firstOrFail();

        $oldAnswer = $response->selected_answer;

        $changes = $response->answer_changes ?? [];
        if ($oldAnswer !== $request->selected_answer) {
            $changes[] = [
                'from' => $oldAnswer,
                'to'   => $request->selected_answer,
                'at'   => now()->toISOString(),
            ];
        }

        $response->update([
            'selected_answer'    => $request->selected_answer,
            'status'             => $request->status,
            'time_spent_seconds' => $response->time_spent_seconds + ($request->time_spent_seconds ?? 0),
            'last_modified_at'   => now(),
            'visit_count'        => $response->visit_count + 1,
            'answer_changes'     => $changes,
            'first_visited_at'   => $response->first_visited_at ?? now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function submit(Request $request, string $attemptUuid): JsonResponse
    {
        $attempt = TestAttempt::where('uuid', $attemptUuid)
                              ->where('status', 'in_progress')
                              ->firstOrFail();

        $submissionType = $attempt->isExpired() ? 'auto_timeout' : 'manual';

        $result = $this->submitService->submit($attempt, $submissionType);

        return response()->json([
            'success'       => true,
            'attempt_uuid'  => $attemptUuid,
            'score'         => $result['score'],
            'percentage'    => $result['percentage'],
            'total_correct' => $result['total_correct'],
            'total_wrong'   => $result['total_wrong'],
            'show_result'   => $result['show_result'],
        ]);
    }

    public function result(string $attemptUuid): JsonResponse
    {
        $attempt = TestAttempt::where('uuid', $attemptUuid)
            ->whereIn('status', ['submitted','completed'])
            ->with([
                'test:id,title,total_marks,show_result_immediately,result_publish_at',
                'student:id,name,roll_number',
            ])
            ->firstOrFail();

        if (!$attempt->test->show_result_immediately) {
            if ($attempt->test->result_publish_at && now()->lt($attempt->test->result_publish_at)) {
                return response()->json(['message' => 'Results will be published after the test window closes.'], 403);
            }
        }

        return response()->json(['success' => true, 'data' => $attempt]);
    }

    public function solutions(string $attemptUuid): JsonResponse
    {
        $attempt = TestAttempt::where('uuid', $attemptUuid)
            ->whereIn('status', ['submitted','completed'])
            ->firstOrFail();

        if (!$attempt->test->show_solutions_after) {
            return response()->json(['message' => 'Solutions are not available for this test.'], 403);
        }

        $responses = $attempt->responses()
            ->with('question:id,question_text,options,correct_answer,explanation,explanation_image,type')
            ->get();

        return response()->json(['success' => true, 'data' => $responses]);
    }
}
