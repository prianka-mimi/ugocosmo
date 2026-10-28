<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * WebAdminMiddleware - Restricts web panel routes to admin/supervisor users.
 *
 * Uses the legacy `users.role` column as the PRIMARY check.
 * Spatie permissions are only used as an ENHANCEMENT for supervisors.
 *
 * This design ensures the admin panel ALWAYS works even if Spatie is
 * misconfigured, out of sync, or throwing exceptions.
 *
 * Usage in routes:
 *   Route::middleware(['auth', 'web-admin'])->group(...)                   — any admin/supervisor
 *   Route::middleware(['auth', 'web-admin:dashboard.view'])->group(...)     — requires specific permission
 *   Route::middleware(['auth', 'web-admin:users.delete'])->group(...)       — requires user delete permission
 */
class WebAdminMiddleware
{
    public function handle(Request $request, Closure $next, ?string $permission = null): mixed
    {
        $user = Auth::user();
        $path = $request->path();

        if (! $user) {
            Log::info('web-admin: no user, redirecting to login from '.$path);

            return redirect()->route('login');
        }

        // ─── PRIMARY CHECK: Legacy role column ──────────────────────
        // This is the source of truth. No Spatie dependency.
        $legacyRole = strtolower(trim((string) $user->role));
        $isAdmin = ($legacyRole === 'admin');

        // ─── SECONDARY CHECK: Spatie roles (with error handling) ────
        // Spatie is used only as a fallback/enhancement.
        // If Spatie throws, we silently ignore and rely on legacy column.
        $hasSpatieRole = false;
        try {
            $hasSpatieRole = $user->hasAnyRole(['admin', 'supervisor']);
        } catch (\Throwable $e) {
            Log::warning('web-admin: Spatie hasAnyRole failed, using legacy only: '.$e->getMessage());
        }

        // Admin: legacy column OR Spatie
        $isSupervisor = false;
        if ($isAdmin) {
            // Admin: full access, no more checks needed
            Log::info("web-admin: admin bypass for {$path}");

            return $next($request);
        }

        // Check supervisor via legacy column
        if ($legacyRole === 'supervisor') {
            $isSupervisor = true;
        }
        // Or via Spatie fallback
        if (! $isSupervisor && $hasSpatieRole) {
            $isSupervisor = true;
        }

        if (! $isSupervisor) {
            Log::warning("web-admin: access denied for user {$user->id} (role='{$legacyRole}') to {$path}");
            abort(403, 'You do not have permission to access this area.');
        }

        // ─── SUPERVISOR: Check specific permission ──────────────────
        if ($permission) {
            // First check: legacy column (admin always has all perms)
            if ($legacyRole === 'admin') {
                return $next($request);
            }

            $permissionNames = explode('|', $permission);
            $hasPermission = false;

            foreach ($permissionNames as $permissionName) {
                $permissionName = trim($permissionName);
                if ($permissionName === '') {
                    continue;
                }

                try {
                    if ($user->can($permissionName)) {
                        $hasPermission = true;
                        break;
                    }
                } catch (\Throwable $e) {
                    Log::warning("web-admin: Spatie can('{$permissionName}') failed: ".$e->getMessage());
                }
            }

            if (! $hasPermission) {
                Log::warning("web-admin: permission '{$permission}' denied for user {$user->id} on {$path}");
                abort(403, 'You do not have the required permission: '.$permission);
            }
        }

        Log::info("web-admin: supervisor access granted for {$path}");

        return $next($request);
    }
}
