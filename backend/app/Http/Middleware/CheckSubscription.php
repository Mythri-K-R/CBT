<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckSubscription
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user || $user->role === 'super_admin') {
            return $next($request);
        }

        $institution = $user->institution;

        if (!$institution) {
            return response()->json(['message' => 'Institution not found.'], 404);
        }

        if (!$institution->isSubscriptionActive()) {
            return response()->json([
                'message'          => 'Subscription expired. Please renew to continue.',
                'subscription_end' => $institution->subscription_end,
            ], 402);
        }

        return $next($request);
    }
}
