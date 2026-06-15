<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Try to find user by username; username is unique per institution
        $user = User::where('username', $request->username)
                    ->when($request->institution_code, function ($q) use ($request) {
                        $q->whereHas('institution', fn ($i) => $i->where('code', $request->institution_code));
                    })
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $user->createToken('auth-token', ['*'], now()->addDays(30))->plainTextToken;

        $this->audit->log('auth.login', 'user', $user->id);

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('institution');

        return response()->json([
            'success' => true,
            'data'    => $this->userPayload($user),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update([
            'password'              => Hash::make($request->password),
            'force_password_change' => false,
        ]);

        return response()->json(['success' => true, 'message' => 'Password changed successfully.']);
    }

    private function userPayload(User $user): array
    {
        $payload = [
            'id'                    => $user->id,
            'uuid'                  => $user->uuid,
            'name'                  => $user->name,
            'email'                 => $user->email,
            'username'              => $user->username,
            'role'                  => $user->role,
            'avatar_path'           => $user->avatar_path,
            'force_password_change' => $user->force_password_change,
            'institution'           => null,
        ];

        if ($user->institution) {
            $payload['institution'] = [
                'id'   => $user->institution->id,
                'name' => $user->institution->name,
                'code' => $user->institution->code,
                'plan' => $user->institution->plan,
                'logo' => $user->institution->logo_path,
            ];
        }

        if ($user->isFaculty()) {
            $payload['permissions'] = $user->getFacultyPermissions();
        }

        return $payload;
    }
}
