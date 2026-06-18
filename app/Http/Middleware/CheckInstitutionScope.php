<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckInstitutionScope
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Super admin bypasses institution scope
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        if (!$user->institution_id) {
            return response()->json(['message' => 'No institution associated with this account.'], 403);
        }

        if (!$user->institution || !$user->institution->is_active) {
            return response()->json(['message' => 'Your institution account is inactive.'], 403);
        }

        return $next($request);
    }
}
