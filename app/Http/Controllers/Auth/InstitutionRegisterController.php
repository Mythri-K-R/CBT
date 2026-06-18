<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\InstitutionEnquiry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InstitutionRegisterController extends Controller
{
    public function submitEnquiry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'institution_name' => 'required|string|max:255',
            'contact_person'   => 'required|string|max:255',
            'email'            => 'required|email',
            'phone'            => 'required|string|max:20',
            'city'             => 'nullable|string|max:100',
            'state'            => 'nullable|string|max:100',
            'student_count'    => 'nullable|integer|min:1',
            'institution_type' => 'nullable|string|max:100',
            'message'          => 'nullable|string',
        ]);

        $enquiry = InstitutionEnquiry::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Thank you! We will contact you within 24 hours.',
            'data'    => ['id' => $enquiry->id],
        ], 201);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'institution_name' => 'required|string|max:255',
            'contact_person'   => 'required|string|max:255',
            'email'            => 'required|email|unique:institutions,email',
            'phone'            => 'required|string|max:20',
            'type'             => 'required|in:pu_college,degree_college,neet_academy,jee_academy,kcet_institute,coaching_center,school,other',
            'city'             => 'nullable|string|max:100',
            'state'            => 'nullable|string|max:100',
            'admin_password'   => 'required|string|min:8',
        ]);

        $trialConfig = config('examsphere.plans.trial');
        $trialDays   = $trialConfig['duration_days'] ?? 30;

        $code = strtoupper(Str::slug($data['institution_name'], ''));
        $code = substr($code, 0, 6).rand(100, 999);

        $institution = Institution::create([
            'code'               => $code,
            'name'               => $data['institution_name'],
            'slug'               => Str::slug($data['institution_name']).'-'.Str::random(4),
            'type'               => $data['type'],
            'contact_person'     => $data['contact_person'],
            'email'              => $data['email'],
            'phone'              => $data['phone'],
            'city'               => $data['city'] ?? null,
            'state'              => $data['state'] ?? null,
            'plan'               => 'trial',
            'student_limit'      => $trialConfig['student_limit'],
            'faculty_limit'      => $trialConfig['faculty_limit'],
            'question_limit'     => $trialConfig['question_limit'],
            'subscription_start' => today(),
            'subscription_end'   => today()->addDays($trialDays),
            'is_active'          => true,
        ]);

        $admin = User::create([
            'institution_id'        => $institution->id,
            'role'                  => 'institution_admin',
            'name'                  => $data['contact_person'],
            'email'                 => $data['email'],
            'username'              => strtolower(str_replace(' ', '.', $data['contact_person'])).rand(10, 99),
            'password'              => Hash::make($data['admin_password']),
            'force_password_change' => false,
            'is_active'             => true,
        ]);

        $token = $admin->createToken('auth-token', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'success'          => true,
            'message'          => "Trial started! You have {$trialDays} days free.",
            'token'            => $token,
            'institution_code' => $institution->code,
            'username'         => $admin->username,
        ], 201);
    }
}
