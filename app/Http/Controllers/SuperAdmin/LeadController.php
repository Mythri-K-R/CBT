<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\InstitutionEnquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $leads = InstitutionEnquiry::query()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where('institution_name', 'like', "%{$request->search}%")
                                                    ->orWhere('email', 'like', "%{$request->search}%"))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $leads]);
    }

    public function show(InstitutionEnquiry $lead): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $lead]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'institution_name' => 'required|string|max:255',
            'contact_person'   => 'required|string|max:255',
            'email'            => 'required|email',
            'phone'            => 'required|string|max:20',
            'city'             => 'nullable|string',
            'state'            => 'nullable|string',
            'student_count'    => 'nullable|integer',
            'institution_type' => 'nullable|string',
            'message'          => 'nullable|string',
        ]);

        $lead = InstitutionEnquiry::create($data);
        return response()->json(['success' => true, 'data' => $lead], 201);
    }

    public function update(Request $request, InstitutionEnquiry $lead): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'nullable|string',
        ]);
        $lead->update($data);
        return response()->json(['success' => true, 'data' => $lead]);
    }

    public function destroy(InstitutionEnquiry $lead): JsonResponse
    {
        $lead->delete();
        return response()->json(['success' => true]);
    }

    public function updateStatus(Request $request, InstitutionEnquiry $lead): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:new_lead,contacted,demo_scheduled,demo_done,converted,rejected',
            'notes'  => 'nullable|string',
        ]);

        $lead->update([
            'status' => $request->status,
            'notes'  => $request->notes ?? $lead->notes,
        ]);

        return response()->json(['success' => true, 'data' => $lead]);
    }

    public function convertToInstitution(Request $request, InstitutionEnquiry $lead): JsonResponse
    {
        $request->validate([
            'type'           => 'required|in:pu_college,degree_college,neet_academy,jee_academy,kcet_institute,coaching_center,school,other',
            'plan'           => 'required|in:trial,starter,growth,enterprise',
            'subscription_end' => 'required|date|after:today',
            'admin_password' => 'required|string|min:8',
        ]);

        $planConfig = config("examsphere.plans.{$request->plan}");

        $institution = Institution::create([
            'code'               => strtoupper(substr(preg_replace('/[^A-Z]/', '', strtoupper($lead->institution_name)), 0, 6)).rand(100, 999),
            'name'               => $lead->institution_name,
            'slug'               => \Illuminate\Support\Str::slug($lead->institution_name).'-'.\Illuminate\Support\Str::random(4),
            'type'               => $request->type,
            'contact_person'     => $lead->contact_person,
            'email'              => $lead->email,
            'phone'              => $lead->phone,
            'city'               => $lead->city,
            'state'              => $lead->state,
            'plan'               => $request->plan,
            'student_limit'      => $planConfig['student_limit'],
            'faculty_limit'      => $planConfig['faculty_limit'],
            'question_limit'     => $planConfig['question_limit'],
            'subscription_start' => today(),
            'subscription_end'   => $request->subscription_end,
            'is_active'          => true,
        ]);

        $username = strtolower(str_replace(' ', '.', $lead->contact_person)).rand(10, 99);

        \App\Models\User::create([
            'institution_id'        => $institution->id,
            'role'                  => 'institution_admin',
            'name'                  => $lead->contact_person,
            'email'                 => $lead->email,
            'username'              => $username,
            'password'              => \Illuminate\Support\Facades\Hash::make($request->admin_password),
            'force_password_change' => true,
            'is_active'             => true,
        ]);

        $lead->update([
            'status'                    => 'converted',
            'converted_institution_id'  => $institution->id,
        ]);

        return response()->json([
            'success'          => true,
            'message'          => 'Institution created.',
            'institution'      => $institution,
            'admin_username'   => $username,
        ], 201);
    }
}
