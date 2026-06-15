<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $studentService) {}

    public function index(Request $request): JsonResponse
    {
        $students = Student::with('batches:id,name,exam_type')
            ->when($request->batch_id, fn ($q) => $q->whereHas('batches', fn ($b) => $b->where('batches.id', $request->batch_id)))
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%")
                                                    ->orWhere('roll_number', 'like', "%{$request->search}%"))
            ->when($request->is_active !== null, fn ($q) => $q->where('is_active', (bool) $request->is_active))
            ->latest()
            ->paginate(25);

        return response()->json(['success' => true, 'data' => $students]);
    }

    public function show(Student $student): JsonResponse
    {
        $student->load(['batches:id,name,exam_type']);
        $student->loadCount('completedAttempts');
        return response()->json(['success' => true, 'data' => $student]);
    }

    public function store(Request $request): JsonResponse
    {
        $institution = $request->user()->institution;

        if (!$institution->isWithinStudentLimit()) {
            return response()->json([
                'message' => "Student limit ({$institution->student_limit}) reached. Upgrade your plan.",
            ], 422);
        }

        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'phone'        => 'nullable|string|max:20',
            'parent_phone' => 'nullable|string|max:20',
            'batch_ids'    => 'nullable|array',
            'batch_ids.*'  => 'exists:batches,id',
        ]);

        $student = $this->studentService->create(
            $request->user()->institution_id,
            $data
        );

        if (!empty($data['batch_ids'])) {
            foreach ($data['batch_ids'] as $batchId) {
                $student->batches()->syncWithoutDetaching([
                    $batchId => ['enrolled_at' => today(), 'status' => 'active']
                ]);
            }
        }

        return response()->json(['success' => true, 'data' => $student], 201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'phone'        => 'nullable|string|max:20',
            'parent_phone' => 'nullable|string|max:20',
            'is_active'    => 'nullable|boolean',
        ]);
        $student->update($data);
        return response()->json(['success' => true, 'data' => $student]);
    }

    public function destroy(Student $student): JsonResponse
    {
        $student->delete();
        return response()->json(['success' => true]);
    }
}
