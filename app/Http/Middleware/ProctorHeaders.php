<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ProctorHeaders
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        // Security headers to make exam pages harder to capture/share
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; img-src 'self' data: blob:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'"
        );

        return $response;
    }
}
