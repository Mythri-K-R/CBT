<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $batches = Batch::withCount('students')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->exam_type, fn ($q) => $q->where('exam_type', $request->exam_type))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $batches]);
    }

    public function show(Batch $batch): JsonResponse
    {
        $batch->load(['students:id,name,roll_number,phone', 'faculty:id,name,email']);
        $batch->loadCount('students');
        return response()->json(['success' => true, 'data' => $batch]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'exam_type'   => 'required|in:neet,jee_main,jee_advanced,kcet,general',
            'batch_type'  => 'nullable|in:regular,crash_course,dropper,repeater,weekend,online,combined',
            'target_year' => 'nullable|integer|min:2024|max:2035',
            'start_date'  => 'nullable|date',
            'description' => 'nullable|string',
            'max_students'=> 'nullable|integer|min:1',
        ]);

        $batch = Batch::create($data);
        return response()->json(['success' => true, 'data' => $batch], 201);
    }

    public function update(Request $request, Batch $batch): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'batch_type'  => 'nullable|in:regular,crash_course,dropper,repeater,weekend,online,combined',
            'target_year' => 'nullable|integer',
            'start_date'  => 'nullable|date',
            'description' => 'nullable|string',
            'max_students'=> 'nullable|integer',
            'status'      => 'nullable|in:active,completed,archived',
            'is_active'   => 'nullable|boolean',
        ]);
        $batch->update($data);
        return response()->json(['success' => true, 'data' => $batch]);
    }

    public function destroy(Batch $batch): JsonResponse
    {
        $batch->delete();
        return response()->json(['success' => true]);
    }

    public function addStudents(Request $request, Batch $batch): JsonResponse
    {
        $request->validate([
            'student_ids'   => 'required|array',
            'student_ids.*' => 'exists:students,id',
        ]);

        $added = 0;
        foreach ($request->student_ids as $studentId) {
            $batch->allStudents()->syncWithoutDetaching([
                $studentId => ['enrolled_at' => today(), 'status' => 'active']
            ]);
            $added++;
        }

        return response()->json(['success' => true, 'message' => "{$added} student(s) added."]);
    }

    public function removeStudent(Batch $batch, Student $student): JsonResponse
    {
        $batch->allStudents()->updateExistingPivot($student->id, ['status' => 'removed']);
        return response()->json(['success' => true]);
    }

    public function addFaculty(Request $request, Batch $batch): JsonResponse
    {
        $request->validate([
            'faculty_ids'   => 'required|array',
            'faculty_ids.*' => 'exists:users,id',
        ]);
        $batch->faculty()->syncWithoutDetaching($request->faculty_ids);
        return response()->json(['success' => true]);
    }

    public function removeFaculty(Batch $batch, User $faculty): JsonResponse
    {
        $batch->faculty()->detach($faculty->id);
        return response()->json(['success' => true]);
    }
}
