<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InstitutionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $institutions = Institution::withTrashed()
            ->when($request->plan, fn ($q) => $q->where('plan', $request->plan))
            ->when($request->is_active !== null, fn ($q) => $q->where('is_active', (bool) $request->is_active))
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%")
                                                    ->orWhere('code', 'like', "%{$request->search}%"))
            ->withCount(['students', 'users', 'questions', 'tests'])
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $institutions]);
    }

    public function show(Institution $institution): JsonResponse
    {
        $institution->loadCount(['students', 'users', 'questions', 'tests', 'batches']);
        $institution->load(['subscriptionHistory' => fn ($q) => $q->latest()->take(5)]);

        $admins = $institution->users()->where('role', 'institution_admin')->get(['id','name','email','username','last_login_at']);

        return response()->json([
            'success' => true,
            'data'    => array_merge($institution->toArray(), ['admins' => $admins]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'type'             => 'required|in:pu_college,degree_college,neet_academy,jee_academy,kcet_institute,coaching_center,school,other',
            'contact_person'   => 'required|string',
            'email'            => 'required|email|unique:institutions,email',
            'phone'            => 'required|string|max:20',
            'city'             => 'nullable|string',
            'state'            => 'nullable|string',
            'plan'             => 'required|in:trial,starter,growth,enterprise',
            'subscription_end' => 'required|date|after:today',
            'admin_name'       => 'required|string',
            'admin_email'      => 'required|email',
            'admin_password'   => 'required|string|min:8',
        ]);

        $planConfig = config("examsphere.plans.{$data['plan']}");
        $slug       = \Illuminate\Support\Str::slug($data['name']);
        $code       = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($slug)));
        $code       = substr($code, 0, 6).rand(100, 999);

        $institution = Institution::create([
            'code'               => $code,
            'name'               => $data['name'],
            'slug'               => $slug.'-'.\Illuminate\Support\Str::random(4),
            'type'               => $data['type'],
            'contact_person'     => $data['contact_person'],
            'email'              => $data['email'],
            'phone'              => $data['phone'],
            'city'               => $data['city'] ?? null,
            'state'              => $data['state'] ?? null,
            'plan'               => $data['plan'],
            'student_limit'      => $planConfig['student_limit'],
            'faculty_limit'      => $planConfig['faculty_limit'],
            'question_limit'     => $planConfig['question_limit'],
            'subscription_start' => today(),
            'subscription_end'   => $data['subscription_end'],
            'is_active'          => true,
        ]);

        User::create([
            'institution_id'        => $institution->id,
            'role'                  => 'institution_admin',
            'name'                  => $data['admin_name'],
            'email'                 => $data['admin_email'],
            'username'              => strtolower(str_replace(' ', '.', $data['admin_name'])).rand(10, 99),
            'password'              => \Illuminate\Support\Facades\Hash::make($data['admin_password']),
            'force_password_change' => true,
            'is_active'             => true,
        ]);

        return response()->json(['success' => true, 'data' => $institution], 201);
    }

    public function update(Request $request, Institution $institution): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'contact_person' => 'sometimes|string',
            'phone'          => 'sometimes|string|max:20',
            'city'           => 'nullable|string',
            'state'          => 'nullable|string',
            'address'        => 'nullable|string',
            'website'        => 'nullable|url',
        ]);

        $institution->update($data);
        return response()->json(['success' => true, 'data' => $institution]);
    }

    public function destroy(Institution $institution): JsonResponse
    {
        $institution->delete();
        return response()->json(['success' => true]);
    }

    public function toggleActive(Institution $institution): JsonResponse
    {
        $institution->update(['is_active' => !$institution->is_active]);
        $status = $institution->is_active ? 'activated' : 'deactivated';
        return response()->json(['success' => true, 'message' => "Institution {$status}.", 'is_active' => $institution->is_active]);
    }

    public function impersonate(Institution $institution): JsonResponse
    {
        $admin = $institution->users()->where('role', 'institution_admin')->firstOrFail();
        $token = $admin->createToken('impersonate-'.Auth::id(), ['*'], now()->addHours(2))->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'message' => "Impersonating {$institution->name} admin.",
        ]);
    }
}
