<?php

namespace App\Http\Controllers\TestAccess;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\TestLink;
use App\Models\TestLinkClick;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestJoinController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $link = TestLink::where('slug', $slug)->with('test:id,title,exam_type,test_category,duration_minutes,scheduled_start,scheduled_end,status')->firstOrFail();

        if (!$link->isValid()) {
            return response()->json(['message' => 'This test link is no longer active.'], 410);
        }

        $this->recordClick($link, $request, 'clicked');

        return response()->json([
            'success' => true,
            'data'    => [
                'test'         => $link->test,
                'link'         => ['slug' => $link->slug, 'has_access_code' => !is_null($link->access_code)],
                'require_code' => !is_null($link->access_code),
            ],
        ]);
    }

    public function listStudents(Request $request, string $slug): JsonResponse
    {
        $link = TestLink::where('slug', $slug)->firstOrFail();

        if (!$link->isValid()) {
            return response()->json(['message' => 'This link is inactive.'], 410);
        }

        // Get students assigned to the test's batches
        $students = Student::whereHas('batches', function ($q) use ($link) {
            $q->whereHas('tests', fn ($t) => $t->where('tests.id', $link->test_id));
        })
        ->where('institution_id', $link->institution_id)
        ->where('is_active', true)
        ->orderBy('name')
        ->get(['id', 'name', 'roll_number']);

        return response()->json(['success' => true, 'data' => $students]);
    }

    public function identify(Request $request, string $slug): JsonResponse
    {
        $link = TestLink::where('slug', $slug)->firstOrFail();

        if (!$link->isValid()) {
            return response()->json(['message' => 'This link is inactive.'], 410);
        }

        $request->validate([
            'student_id'  => 'required_without:roll_number|nullable|integer',
            'roll_number' => 'required_without:student_id|nullable|string',
            'access_code' => 'nullable|string',
        ]);

        // Validate access code if set
        if ($link->access_code && $request->access_code !== $link->access_code) {
            return response()->json(['message' => 'Invalid access code.'], 403);
        }

        // Find the student
        if ($request->student_id) {
            $student = Student::where('id', $request->student_id)
                               ->where('institution_id', $link->institution_id)
                               ->where('is_active', true)
                               ->first();
        } else {
            $student = Student::where('roll_number', $request->roll_number)
                               ->where('institution_id', $link->institution_id)
                               ->where('is_active', true)
                               ->first();
        }

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        // Check if student already has an attempt for this test
        $existingAttempt = \App\Models\TestAttempt::where('test_id', $link->test_id)
                                                   ->where('student_id', $student->id)
                                                   ->first();

        $this->recordClick($link, $request, 'identified', $student->id);
        $link->increment('total_clicks');

        return response()->json([
            'success' => true,
            'data'    => [
                'student'          => $student->only(['id', 'name', 'roll_number']),
                'already_attempted'=> $existingAttempt !== null,
                'can_attempt'      => $existingAttempt === null || ($link->test->max_attempts > 1 && optional($existingAttempt)->status !== 'in_progress'),
            ],
        ]);
    }

    private function recordClick(TestLink $link, Request $request, string $action, ?int $studentId = null): void
    {
        TestLinkClick::create([
            'link_id'    => $link->id,
            'student_id' => $studentId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referrer'   => $request->header('Referer'),
            'action'     => $action,
        ]);
    }
}
