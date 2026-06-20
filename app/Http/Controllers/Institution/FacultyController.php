<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class FacultyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $faculty = User::where('role', 'faculty')
            ->with('subjects:id,name,exam_type')
            ->withCount('createdQuestions')
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->is_active !== null, fn ($q) => $q->where('is_active', (bool) $request->is_active))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $faculty]);
    }

    public function show(User $faculty): JsonResponse
    {
        $faculty->load(['subjects', 'batches:id,name']);
        $faculty->loadCount(['createdQuestions', 'createdTests']);
        return response()->json(['success' => true, 'data' => $faculty]);
    }

    public function store(Request $request): JsonResponse
    {
        $institution = $request->user()->institution;

        if (!$institution->isWithinFacultyLimit()) {
            return response()->json([
                'message' => "Faculty limit ({$institution->faculty_limit}) reached. Upgrade your plan.",
            ], 422);
        }

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'nullable|email',
            'phone'    => 'nullable|string|max:20',
            'username' => 'required|string|max:100',
            'password' => 'required|string|min:8',
        ]);

        // Ensure username is unique within institution
        $existingUsername = User::where('institution_id', $institution->id)
                                ->where('username', $data['username'])
                                ->exists();
        if ($existingUsername) {
            return response()->json(['message' => 'Username already taken in this institution.'], 422);
        }

        $faculty = User::create([
            'institution_id'        => $institution->id,
            'role'                  => 'faculty',
            'name'                  => $data['name'],
            'email'                 => $data['email'] ?? null,
            'phone'                 => $data['phone'] ?? null,
            'username'              => $data['username'],
            'password'              => Hash::make($data['password']),
            'force_password_change' => true,
            'is_active'             => true,
        ]);

        return response()->json(['success' => true, 'data' => $faculty], 201);
    }

    public function update(Request $request, User $faculty): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'sometimes|string',
            'email'     => 'nullable|email',
            'phone'     => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $faculty->update($data);
        return response()->json(['success' => true, 'data' => $faculty]);
    }

    public function destroy(User $faculty): JsonResponse
    {
        $faculty->delete();
        return response()->json(['success' => true]);
    }

    public function updatePermissions(Request $request, User $faculty): JsonResponse
    {
        $data = $request->validate([
            'can_create_questions' => 'nullable|boolean',
            'can_edit_questions'   => 'nullable|boolean',
            'can_create_tests'     => 'nullable|boolean',
            'can_schedule_tests'   => 'nullable|boolean',
            'can_view_results'     => 'nullable|boolean',
            'can_view_analytics'   => 'nullable|boolean',
            'can_generate_links'   => 'nullable|boolean',
            'can_manage_students'  => 'nullable|boolean',
        ]);
        $faculty->update($data);
        return response()->json(['success' => true, 'data' => $faculty->getFacultyPermissions()]);
    }

    public function updateSubjects(Request $request, User $faculty): JsonResponse
    {
        $request->validate([
            'subject_ids'   => 'required|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);
        $faculty->subjects()->sync($request->subject_ids);
        return response()->json(['success' => true, 'message' => 'Subjects updated.']);
    }

    public function resetPassword(Request $request, User $faculty): JsonResponse
    {
        $request->validate(['password' => 'required|string|min:8']);
        $faculty->update([
            'password'              => Hash::make($request->password),
            'force_password_change' => true,
        ]);
        $faculty->tokens()->delete();
        return response()->json(['success' => true, 'message' => 'Password reset. Faculty must login again.']);
    }
}
