<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Services\Analytics\TestAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    public function __construct(private readonly TestAnalyticsService $analytics) {}

    public function index(Request $request, Test $test): JsonResponse
    {
        $attempts = TestAttempt::where('test_id', $test->id)
            ->with('student:id,name,roll_number')
            ->whereIn('status', ['submitted','completed'])
            ->when($request->batch_id, fn ($q) => $q->whereHas('student.batches', fn ($b) => $b->where('batches.id', $request->batch_id)))
            ->orderByDesc('total_score')
            ->paginate(50);

        return response()->json(['success' => true, 'data' => $attempts]);
    }

    public function show(Test $test, TestAttempt $attempt): JsonResponse
    {
        $attempt->load([
            'student:id,name,roll_number',
            'responses.question',
            'proctorEvents',
        ]);
        return response()->json(['success' => true, 'data' => $attempt]);
    }

    public function rankings(Test $test): JsonResponse
    {
        $rankings = TestAttempt::where('test_id', $test->id)
            ->whereIn('status', ['submitted','completed'])
            ->with('student:id,name,roll_number')
            ->orderByDesc('total_score')
            ->get(['id','student_id','total_score','percentage','accuracy','rank_in_test','percentile','total_correct','total_wrong','time_spent_seconds']);

        return response()->json(['success' => true, 'data' => $rankings]);
    }

    public function analytics(Test $test): JsonResponse
    {
        $data = $this->analytics->analyze($test);
        return response()->json(['success' => true, 'data' => $data]);
    }
}
