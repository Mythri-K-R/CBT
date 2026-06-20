<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * P0-1 — Horizon Dashboard Security
 *
 * Extends HorizonApplicationServiceProvider, which already registers the
 * Horizon::auth() gate check on every dashboard request.
 *
 * The gate defined below is the single source of truth for Horizon access:
 *   ✅ super_admin → allowed
 *   ❌ institution_admin, faculty, student, guest → HTTP 403
 *
 * Unauthenticated users receive the 403 response from Horizon's built-in
 * guard (it does not redirect to login — intentional for an API-first platform).
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Define the Horizon authorization gate.
     *
     * Called by HorizonApplicationServiceProvider::boot() and wired into
     * Horizon::auth() automatically — no manual Horizon::auth() call needed.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user): bool {
            return $user->isSuperAdmin();
        });
    }
}
