<?php

namespace App\Http\Controllers\TestAccess;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\TestAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentResultsController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'roll_number'      => 'required|string',
            'institution_code' => 'required|string',
        ]);

        $student = Student::whereHas('institution', fn ($q) => $q->where('code', $request->institution_code))
                          ->where('roll_number', $request->roll_number)
                          ->where('is_active', true)
                          ->first();

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $attempts = TestAttempt::where('student_id', $student->id)
            ->whereIn('status', ['submitted','completed'])
            ->with('test:id,title,test_category,exam_type,total_marks')
            ->latest('submitted_at')
            ->get(['id','uuid','test_id','total_score','percentage','rank_in_test','percentile','submitted_at']);

        return response()->json([
            'success'  => true,
            'student'  => $student->only(['name','roll_number']),
            'attempts' => $attempts,
        ]);
    }

    public function show(string $attemptUuid): JsonResponse
    {
        $attempt = TestAttempt::where('uuid', $attemptUuid)
            ->whereIn('status', ['submitted','completed'])
            ->with([
                'test:id,title,total_marks,show_solutions_after,result_publish_at',
                'student:id,name,roll_number',
            ])
            ->firstOrFail();

        // Check if results are released
        if ($attempt->test->result_publish_at && now()->lt($attempt->test->result_publish_at)) {
            return response()->json(['message' => 'Results are not yet published.'], 403);
        }

        return response()->json(['success' => true, 'data' => $attempt]);
    }
}
