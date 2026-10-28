<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * AdminMiddleware - Restricts API access to admin/supervisor users only.
 *
 * PRIMARY CHECK: Legacy `role` column on users table.
 * SECONDARY CHECK: Spatie roles as fallback (with error handling).
 *
 * If Spatie is misconfigured or throwing exceptions, the middleware
 * falls back to the legacy role column to ensure continuous access.
 *
 * Role hierarchy:
 * - 'admin'       → Full access to everything
 * - 'supervisor'  → Limited access (what admin grants via role manager)
 * - 'user'        → Regular users (blocked from admin routes)
 *
 * Usage in routes:
 *   Route::middleware(['auth:jwt', 'admin'])->group(...)
 *   Route::middleware(['auth:jwt', 'admin:supervisor'])->group(...) — allows supervisor
 *   Route::middleware(['auth:jwt', 'admin:admin'])->group(...) — admin only (strict)
 */
class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  string|null  $minRole  Minimum role required: 'supervisor' or 'admin'
     */
    public function handle(Request $request, Closure $next, ?string $minRole = 'supervisor'): mixed
    {
        $user = Auth::guard('jwt')->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
                'data' => [],
                'status_message' => 'Please login first',
                'status_code' => 401,
            ], 401);
        }

        // ─── PRIMARY CHECK: Legacy role column ──────────────────────
        $legacyRole = $user->role;

        // ─── SECONDARY CHECK: Spatie roles (with error handling) ────
        $hasSpatieRole = false;
        try {
            $hasSpatieRole = $user->hasAnyRole(['admin', 'supervisor']);
        } catch (\Throwable $e) {
            Log::warning('admin: Spatie hasAnyRole failed, using legacy only: '.$e->getMessage());
        }

        // Determine effective role: legacy column primary, Spatie fallback
        $effectiveAdmin = $legacyRole === 'admin' || ($hasSpatieRole && $this->safeHasRole($user, 'admin'));
        $effectiveSupervisor = in_array($legacyRole, ['admin', 'supervisor']) || $hasSpatieRole;

        $allowed = match ($minRole) {
            'admin' => $effectiveAdmin,
            'supervisor' => $effectiveSupervisor,
            default => $effectiveSupervisor,
        };

        if (! $allowed) {
            Log::warning("admin: access denied for user {$user->id} (role='{$legacyRole}') to {$request->path()}");

            return response()->json([
                'status' => false,
                'message' => 'Forbidden',
                'data' => [],
                'status_message' => 'You do not have permission to access this resource',
                'status_code' => 403,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Safely check if user has a specific role, catching Spatie exceptions.
     */
    private function safeHasRole($user, string $role): bool
    {
        try {
            return $user->hasRole($role);
        } catch (\Throwable $e) {
            Log::warning("admin: Spatie hasRole('{$role}') failed: ".$e->getMessage());

            return false;
        }
    }
}
