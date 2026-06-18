<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->route('login');
        }

        if (!in_array($user->role, $roles)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'You do not have permission to access this resource.'], 403)
                : redirect()->route('dashboard');
        }

        return $next($request);
    }
}
