<?php

namespace App\Http\Controllers\TestAccess;

use App\Http\Controllers\Controller;
use App\Models\TestLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestInstructionsController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $link = TestLink::where('slug', $slug)
            ->with(['test' => fn ($q) => $q->with('sections:id,test_id,name,question_count,positive_marks,negative_marks,duration_minutes')])
            ->firstOrFail();

        if (!$link->isValid()) {
            return response()->json(['message' => 'This link is inactive.'], 410);
        }

        $test = $link->test;

        $instructions = $test->instructions ?? $this->getDefaultInstructions($test);

        return response()->json([
            'success' => true,
            'data'    => [
                'test' => [
                    'title'            => $test->title,
                    'duration_minutes' => $test->duration_minutes,
                    'total_questions'  => $test->total_questions,
                    'total_marks'      => $test->total_marks,
                    'exam_type'        => $test->exam_type,
                    'sections'         => $test->sections,
                    'anti_cheat'       => [
                        'fullscreen_required'      => $test->fullscreen_required,
                        'tab_switch_limit'         => $test->tab_switch_limit,
                        'disable_copy_paste'       => $test->disable_copy_paste,
                        'auto_submit_on_violation' => $test->auto_submit_on_violation,
                    ],
                ],
                'instructions' => $instructions,
            ],
        ]);
    }

    private function getDefaultInstructions(\App\Models\Test $test): string
    {
        return "Please read these instructions carefully before starting the test.

1. The total duration is {$test->duration_minutes} minutes. The test will auto-submit when time expires.
2. Do NOT close the browser window or switch tabs during the test.
3. Ensure you are in fullscreen mode throughout the examination.
4. Each correct answer carries positive marks. Wrong answers carry negative marks.
5. You can mark questions for review and return to them later.
6. Click 'Submit Test' only when you are ready to end the exam. This action is irreversible.
7. Your progress is saved automatically every time you answer a question.";
    }
}
