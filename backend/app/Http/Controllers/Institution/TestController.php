<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Test;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $tests = Test::with('creator:id,name')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->exam_type, fn ($q) => $q->where('exam_type', $request->exam_type))
            ->when($request->category, fn ($q) => $q->where('test_category', $request->category))
            ->when($request->search, fn ($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $tests]);
    }

    public function show(Test $test): JsonResponse
    {
        $test->load(['sections.subject', 'testQuestions.question', 'batches:id,name', 'links']);
        return response()->json(['success' => true, 'data' => $test]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTest($request);
        $data['created_by'] = $request->user()->id;
        $test = Test::create($data);
        $this->audit->log('test.created', 'test', $test->id);
        return response()->json(['success' => true, 'data' => $test], 201);
    }

    public function update(Request $request, Test $test): JsonResponse
    {
        abort_if($test->status === 'live', 422, 'Cannot edit a live test.');
        $test->update($this->validateTest($request, false));
        return response()->json(['success' => true, 'data' => $test]);
    }

    public function destroy(Test $test): JsonResponse
    {
        abort_if($test->status === 'live', 422, 'Cannot delete a live test.');
        $test->delete();
        return response()->json(['success' => true]);
    }

    public function schedule(Request $request, Test $test): JsonResponse
    {
        $request->validate([
            'scheduled_start'   => 'required|date|after:now',
            'scheduled_end'     => 'required|date|after:scheduled_start',
            'result_publish_at' => 'nullable|date|after:scheduled_end',
            'batch_ids'         => 'nullable|array',
            'batch_ids.*'       => 'exists:batches,id',
        ]);

        abort_if($test->total_questions === 0, 422, 'Cannot schedule a test with no questions.');

        $test->update([
            'status'            => 'scheduled',
            'scheduled_start'   => $request->scheduled_start,
            'scheduled_end'     => $request->scheduled_end,
            'result_publish_at' => $request->result_publish_at,
        ]);

        if ($request->batch_ids) {
            $test->batches()->sync($request->batch_ids);

            $total = 0;
            foreach ($request->batch_ids as $batchId) {
                $count = \App\Models\BatchStudent::where('batch_id', $batchId)->where('status', 'active')->count();
                $total += $count;
            }
            $test->update(['total_assigned_students' => $total]);
        }

        $this->audit->log('test.scheduled', 'test', $test->id);
        return response()->json(['success' => true, 'data' => $test]);
    }

    public function activate(Request $request, Test $test): JsonResponse
    {
        abort_if(!in_array($test->status, ['draft','scheduled']), 422, 'Test cannot be activated.');
        $test->update(['status' => 'live']);
        $this->audit->log('test.activated', 'test', $test->id);
        return response()->json(['success' => true, 'data' => $test]);
    }

    public function complete(Request $request, Test $test): JsonResponse
    {
        abort_if($test->status !== 'live', 422, 'Only live tests can be completed.');
        $test->update(['status' => 'completed']);
        \App\Jobs\CalculateTestRankings::dispatch($test->id);
        $this->audit->log('test.completed', 'test', $test->id);
        return response()->json(['success' => true, 'message' => 'Test marked complete. Rankings being calculated.']);
    }

    private function validateTest(Request $request, bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';
        return $request->validate([
            'title'                    => "{$r}|string|max:255",
            'description'              => 'nullable|string',
            'exam_type'                => "{$r}|in:neet,jee_main,jee_advanced,kcet",
            'test_category'            => "nullable|in:chapter_test,subject_test,weekly_test,mock_test,grand_test,practice_test,custom",
            'duration_minutes'         => "{$r}|integer|min:1",
            'has_sectional_timing'     => 'nullable|boolean',
            'allow_section_switching'  => 'nullable|boolean',
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
            'template_id'              => 'nullable|exists:exam_templates,id',
        ]);
    }
}
