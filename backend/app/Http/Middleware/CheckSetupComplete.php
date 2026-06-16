<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckSetupComplete
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user && $user->force_password_change && !$request->routeIs('institution.setup')) {
            return redirect()->route('institution.setup');
        }

        return $next($request);
    }
}
