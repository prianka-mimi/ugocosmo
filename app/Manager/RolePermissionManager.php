<?php

namespace App\Manager;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

class RolePermissionManager
{
    /**
     * All available permission keys grouped by module.
     * This is the SINGLE canonical source of truth.
     */
    const ALL_PERMISSIONS = [
        'Dashboard' => [
            'dashboard.view' => 'View Dashboard Analytics',
        ],
        'Users' => [
            'users.view' => 'View Users List',
            'users.create' => 'Create Users',
            'users.edit' => 'Edit Users',
            'users.delete' => 'Delete Users',
            'users.suspend' => 'Suspend/Activate Users',
            'users.verify' => 'Verify Users (email)',
        ],
        'Content - Posts' => [
            'posts.view' => 'View Posts List',
            'posts.moderate' => 'Deactivate/Reactivate Posts',
        ],
        'Content - Products' => [
            'products.view' => 'View Products List',
            'products.moderate' => 'Deactivate/Reactivate Products',
        ],
        'Content - Services' => [
            'services.view' => 'View Services List',
            'services.moderate' => 'Deactivate/Reactivate Services',
        ],
        'Content - Events' => [
            'events.view' => 'View Events List',
            'events.moderate' => 'Deactivate/Reactivate Events',
        ],
        'Orders' => [
            'orders.view' => 'View Orders List & Details',
            'orders.manage' => 'Update Order Status & Process Refunds',
        ],
        'Disputes' => [
            'disputes.view' => 'View Disputes List & Details',
            'disputes.manage' => 'Resolve & Dismiss Disputes',
        ],
        'AliExpress' => [
            'aliexpress.view' => 'View AliExpress Connections & Logs',
            'aliexpress.manage' => 'Disable/Re-enable/Refresh AliExpress Connections',
        ],
        'Contact Messages' => [
            'conversations.view' => 'View Conversations & Messages',
            'conversations.reply' => 'Reply to Conversations',
            'conversations.assign' => 'Assign Conversations to Supervisors',
        ],
        'FAQ' => [
            'faq.manage' => 'Manage FAQ (CRUD + toggle status)',
        ],
        'Pages' => [
            'pages.manage' => 'Manage CMS Pages (CRUD + toggle status)',
        ],
        'Subscriptions' => [
            'subscriptions.manage' => 'Manage Subscription Plans',
        ],
        'Verifications' => [
            'verifications.view' => 'View Verification Submissions',
            'verifications.manage' => 'Approve/Reject Verification Submissions',
        ],
        'Settings' => [
            'settings.manage' => 'Manage App Settings (branding)',
        ],
        'Roles & Permissions' => [
            'roles.view' => 'View Roles & Distribution',
            'roles.assign' => 'Assign Roles to Users',
            'permissions.manage' => 'Manage Role Permissions',
        ],
        'Audit' => [
            'audit.view' => 'View Audit Logs',
        ],
    ];

    /**
     * Default permissions for the supervisor role.
     * Admin gets everything automatically.
     */
    const SUPERVISOR_DEFAULTS = [
        'dashboard.view',
        'users.view',
        'users.create',
        'users.edit',
        'users.suspend',
        'posts.view',
        'posts.moderate',
        'products.view',
        'products.moderate',
        'services.view',
        'services.moderate',
        'events.view',
        'events.moderate',
        'orders.view',
        'orders.manage',
        'disputes.view',
        'disputes.manage',
        'conversations.view',
        'conversations.reply',
        'faq.manage',
        'pages.manage',
        'subscriptions.manage',
        'verifications.view',
        'verifications.manage',
    ];

    /**
     * Default permissions for the user role.
     * Basic access only � no admin or moderation capabilities.
     */
    const USER_DEFAULTS = [
        'dashboard.view',
    ];

    /**
     * Returns the grouped permissions structure including discovered routes.
     */
    public function getAllPermissions(): array
    {
        $perms = self::ALL_PERMISSIONS;

        if (! empty($this->discoveredPermissions)) {
            $perms['Discovered'] = [];
            foreach ($this->discoveredPermissions as $perm) {
                $perms['Discovered'][$perm] = ucfirst(str_replace(['.', '_', '-'], ' ', $perm));
            }
        }

        return $perms;
    }

    /**
     * Returns all permission keys as a flat array.
     */
    public function getAllPermissionNames(): array
    {
        return array_keys(array_merge(...array_values(self::ALL_PERMISSIONS)));
    }

    /**
     * Get user count grouped by role.
     */
    public function getRoleDistribution(): Collection
    {
        $spatieRoles = Role::orderBy('name')->get()->keyBy(fn ($r) => strtolower($r->name));

        $userCounts = User::selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->get()
            ->keyBy(fn ($u) => strtolower($u->role));

        $rows = collect();

        foreach ($spatieRoles as $nameLower => $role) {
            $count = $userCounts->has($nameLower) ? (int) $userCounts[$nameLower]->count : 0;
            $rows->push((object) ['role' => $role->name, 'count' => $count]);
            unset($userCounts[$nameLower]);
        }

        foreach ($userCounts as $roleName => $countObj) {
            $rows->push((object) ['role' => $roleName, 'count' => (int) $countObj->count]);
        }

        return $rows->sortByDesc('count')->values();
    }

    /**
     * Get permission names for a given Spatie role.
     * Admin always returns all possible permissions.
     */
    public function getRolePermissions(string $role): array
    {
        if ($role === 'admin') {
            return $this->getAllPermissionNames();
        }

        $spatieRole = Role::where('name', $role)->first();
        if (! $spatieRole) {
            return [];
        }

        return $spatieRole->permissions->pluck('name')->toArray();
    }

    /**
     * Check if a role exists in the Spatie roles table.
     */
    public function roleExists(string $role): bool
    {
        return Role::where('name', $role)->where('guard_name', 'web')->exists();
    }

    /**
     * Get available roles (Spatie roles + legacy user roles).
     */
    public function getAvailableRoles(): array
    {
        $spatieRoles = Role::pluck('name')->map(fn ($n) => strtolower($n))->toArray();
        $distribution = $this->getRoleDistribution();
        $legacyRoles = $distribution->pluck('role')->map(fn ($n) => strtolower($n))->filter()->values()->toArray();

        return array_values(array_unique(array_merge($spatieRoles, $legacyRoles)));
    }

    /**
     * Sync permissions for a Spatie role (replaces all).
     * Also finds or creates the Spatie permission records.
     */
    public function syncRolePermissions(string $role, array $permissionNames): bool
    {
        try {
            DB::beginTransaction();

            $spatieRole = Role::findOrCreate($role, 'web');

            // Ensure all permission names exist in Spatie's permissions table
            foreach ($permissionNames as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $spatieRole->syncPermissions($permissionNames);

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role permission sync failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Create a new custom role with optional permissions.
     */
    public function createRole(string $role, array $permissionNames = [], string $status = 'active'): bool
    {
        try {
            DB::beginTransaction();

            if (Role::where('name', $role)->where('guard_name', 'web')->exists()) {
                throw new \Exception("Role '{$role}' already exists.");
            }

            Role::create(['name' => $role, 'guard_name' => 'web', 'status' => $status]);

            if (! empty($permissionNames)) {
                $this->syncRolePermissions($role, $permissionNames);
            }

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role creation failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Delete a custom role. Protected roles (admin, supervisor) cannot be deleted.
     */
    public function deleteRole(string $role): bool
    {
        try {
            DB::beginTransaction();

            if (in_array($role, ['admin', 'supervisor'])) {
                throw new \Exception("Cannot delete protected role: {$role}");
            }

            $spatieRole = Role::where('name', $role)->where('guard_name', 'web')->first();
            if ($spatieRole) {
                $spatieRole->delete();
            }

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role deletion failed: '.$e->getMessage());

            return false;
        }
    }

    public function updateRoleStatus(string $role, string $status): bool
    {
        try {
            DB::beginTransaction();

            $spatieRole = Role::where('name', $role)->where('guard_name', 'web')->first();
            if (! $spatieRole) {
                throw new \Exception("Role '{$role}' not found.");
            }

            $spatieRole->update(['status' => $status]);

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role status update failed: '.$e->getMessage());

            return false;
        }
    }

    public function getRoleStatus(string $role): string
    {
        $spatieRole = Role::where('name', $role)->where('guard_name', 'web')->first();

        return $spatieRole ? $spatieRole->status ?? 'active' : 'active';
    }

    /**
     * Assign a role to a user, updating both Spatie roles and legacy column.
     */
    public function assignRole(User $user, string $role, ?int $adminProfileId = null): User
    {
        $normalizedRole = strtolower(trim($role));
        $oldRole = $user->role;

        if ($user->role === 'admin' || $user->email === 'admin@admin.com') {
            throw new \Exception('Cannot change role or status of the main admin user.');
        }

        $user->update(['role' => $normalizedRole, 'status' => 'active']);

        if (in_array($normalizedRole, ['admin', 'supervisor'], true)) {
            Role::findOrCreate($normalizedRole, 'web');
            $user->syncRoles([$normalizedRole]);
        } else {
            $user->syncRoles([]);
        }

        // Log audit
        if ($adminProfileId) {
            AdminAuditLog::create([
                'admin_id' => $adminProfileId,
                'action' => 'role_assigned',
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'old_value' => ['role' => $oldRole],
                'new_value' => ['role' => $role],
                'ip_address' => request()->ip(),
            ]);
        }

        return $user->fresh();
    }

    /**
     * Seed all permissions and default roles into Spatie tables.
     * Designed to be idempotent (safe to call multiple times).
     * Scans all registered routes for new permission patterns.
     */
    public function seedDefaults(): void
    {
        DB::transaction(function () {
            // 0. Scan all routes for new permission keys
            $this->scanRoutesForPermissions();

            // 1. Create all permissions
            foreach ($this->getAllPermissionNames() as $name) {
                Permission::findOrCreate($name, 'web');
            }

            // 2. Admin role gets ALL permissions
            $adminRole = Role::findOrCreate('admin', 'web');
            $adminRole->syncPermissions(Permission::all());

            // 3. Supervisor role gets defaults
            $supervisorRole = Role::findOrCreate('supervisor', 'web');
            $supervisorRole->syncPermissions(self::SUPERVISOR_DEFAULTS);

            // 4. User role gets basic defaults
            $userRole = Role::findOrCreate('user', 'web');
            $userRole->syncPermissions(self::USER_DEFAULTS);

            // 5. Sync existing users (without firing model events to avoid recursion)
            User::withoutEvents(function () {
                User::whereIn('role', ['admin', 'supervisor', 'user'])->each(function ($user) {
                    $user->syncRoles([$user->role]);
                });

                User::whereNotIn('role', ['admin', 'supervisor', 'user'])->each(function ($user) {
                    if ($user->roles->isNotEmpty()) {
                        $user->syncRoles([]);
                    }
                });
            });
        });
    }

    /**
     * Scan all registered routes and auto-generate permission keys.
     * Looks for web-admin middleware patterns and adds new permissions.
     */
    public function scanRoutesForPermissions(): void
    {
        $routes = Route::getRoutes()->getRoutes();
        $knownPermissions = $this->getAllPermissionNames();
        $newPermissions = [];

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            // Look for web-admin:permission patterns
            foreach ($middleware as $m) {
                if (str_starts_with($m, 'web-admin:')) {
                    $perm = str_replace('web-admin:', '', $m);
                    if (! in_array($perm, $knownPermissions) && ! in_array($perm, $newPermissions)) {
                        $newPermissions[] = $perm;
                    }
                }
            }
        }

        if (! empty($newPermissions)) {
            // Store discovered permissions for the current request
            $this->discoveredPermissions = $newPermissions;
        }
    }

    protected array $discoveredPermissions = [];

    public function getDiscoveredPermissions(): array
    {
        return $this->discoveredPermissions;
    }
}
