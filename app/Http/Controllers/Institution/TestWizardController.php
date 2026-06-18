<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Test;
use App\Models\TestSection;
use App\Services\QuestionBank\AutoPickService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestWizardController extends Controller
{
    public function __construct(private readonly AutoPickService $autoPick) {}

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'exam_type'      => 'required|in:neet,jee_main,jee_advanced,kcet',
            'test_category'  => 'required|in:chapter_test,subject_test,weekly_test,mock_test,grand_test,practice_test,custom',
            'duration_minutes' => 'required|integer|min:1',
            'template_id'    => 'nullable|exists:exam_templates,id',
        ]);
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'draft';

        $test = Test::create($data);
        return response()->json(['success' => true, 'data' => $test, 'step' => 'sections'], 201);
    }

    public function updateSections(Request $request, Test $test): JsonResponse
    {
        abort_if($test->status !== 'draft', 422, 'Can only edit draft tests.');

        $request->validate([
            'sections'                 => 'required|array|min:1',
            'sections.*.name'          => 'required|string',
            'sections.*.subject_id'    => 'nullable|exists:subjects,id',
            'sections.*.question_count'=> 'required|integer|min:1',
            'sections.*.positive_marks'=> 'required|numeric|min:0',
            'sections.*.negative_marks'=> 'required|numeric|min:0',
            'sections.*.mandatory_count' => 'nullable|integer',
            'sections.*.duration_minutes'=> 'nullable|integer',
        ]);

        DB::transaction(function () use ($test, $request) {
            $test->sections()->delete();
            foreach ($request->sections as $order => $sectionData) {
                TestSection::create(array_merge($sectionData, [
                    'test_id'       => $test->id,
                    'display_order' => $order,
                ]));
            }
        });

        return response()->json([
            'success' => true,
            'data'    => $test->fresh()->load('sections'),
            'step'    => 'questions',
        ]);
    }

    public function updateQuestions(Request $request, Test $test): JsonResponse
    {
        abort_if($test->status !== 'draft', 422, 'Can only edit draft tests.');

        $request->validate([
            'mode'                      => 'required|in:manual,auto',
            // Manual mode
            'questions'                 => 'required_if:mode,manual|array',
            'questions.*.question_id'   => 'required_if:mode,manual|exists:questions,id',
            'questions.*.section_id'    => 'nullable|exists:test_sections,id',
            'questions.*.positive_marks'=> 'required_if:mode,manual|numeric',
            'questions.*.negative_marks'=> 'required_if:mode,manual|numeric',
            // Auto mode
            'auto_config'               => 'required_if:mode,auto|array',
        ]);

        DB::transaction(function () use ($test, $request) {
            $test->testQuestions()->delete();

            if ($request->mode === 'auto') {
                $questions = $this->autoPick->pick($test, $request->auto_config);
            } else {
                $questions = $request->questions;
            }

            $totalMarks = 0;
            foreach ($questions as $order => $q) {
                $questionId   = $q['question_id'] ?? $q->id;
                $posMarks     = $q['positive_marks'] ?? 4;
                $negMarks     = $q['negative_marks'] ?? 1;
                $sectionId    = $q['section_id'] ?? null;

                $test->testQuestions()->create([
                    'question_id'     => $questionId,
                    'section_id'      => $sectionId,
                    'question_number' => $order + 1,
                    'positive_marks'  => $posMarks,
                    'negative_marks'  => $negMarks,
                ]);
                $totalMarks += $posMarks;
            }

            $test->update([
                'total_questions' => count($questions),
                'total_marks'     => $totalMarks,
            ]);
        });

        return response()->json([
            'success' => true,
            'data'    => ['total_questions' => $test->total_questions, 'total_marks' => $test->total_marks],
            'step'    => 'settings',
        ]);
    }

    public function updateSettings(Request $request, Test $test): JsonResponse
    {
        $data = $request->validate([
            'shuffle_questions'        => 'nullable|boolean',
            'shuffle_options'          => 'nullable|boolean',
            'show_result_immediately'  => 'nullable|boolean',
            'show_solutions_after'     => 'nullable|boolean',
            'max_attempts'             => 'nullable|integer|min:1',
            'fullscreen_required'      => 'nullable|boolean',
            'tab_switch_limit'         => 'nullable|integer|min:0',
            'disable_copy_paste'       => 'nullable|boolean',
            'disable_right_click'      => 'nullable|boolean',
            'auto_submit_on_violation' => 'nullable|boolean',
            'instructions'             => 'nullable|string',
        ]);
        $test->update($data);
        return response()->json(['success' => true, 'data' => $test, 'step' => 'publish']);
    }

    public function publish(Request $request, Test $test): JsonResponse
    {
        abort_if($test->total_questions === 0, 422, 'Add questions before publishing.');

        $request->validate([
            'scheduled_start' => 'nullable|date|after:now',
            'scheduled_end'   => 'nullable|date|after:scheduled_start',
            'batch_ids'       => 'nullable|array',
        ]);

        $status = $request->scheduled_start ? 'scheduled' : 'live';

        $test->update(array_filter([
            'status'          => $status,
            'scheduled_start' => $request->scheduled_start,
            'scheduled_end'   => $request->scheduled_end,
        ], fn ($v) => $v !== null));

        if ($request->batch_ids) {
            $test->batches()->sync($request->batch_ids);
        }

        return response()->json(['success' => true, 'data' => $test->fresh(), 'status' => $status]);
    }
}
