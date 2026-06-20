<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckFacultyPermission
{
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Institution admins and super admins always have permission
        if (in_array($user->role, ['super_admin', 'institution_admin'])) {
            return $next($request);
        }

        // Faculty: check specific permission
        if ($user->role === 'faculty' && !$user->hasPermission($permission)) {
            return response()->json([
                'message'    => 'You do not have permission to perform this action.',
                'permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
