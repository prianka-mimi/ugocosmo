<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Manager\RolePermissionManager;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Throwable;

class RolePermissionController extends Controller
{
    protected RolePermissionManager $rolePermissionManager;

    public function __construct(RolePermissionManager $rolePermissionManager)
    {
        $this->rolePermissionManager = $rolePermissionManager;
    }

    public function index()
    {
        $selectedSection = request('section', 'roles');
        $roles = Role::with('permissions')->get()->keyBy('name');
        $this->rolePermissionManager->scanRoutesForPermissions();
        $allPermissions = $this->rolePermissionManager->getAllPermissions();
        $roleDistribution = $this->rolePermissionManager->getRoleDistribution();
        $availableRoles = $this->rolePermissionManager->getAvailableRoles();
        $roleCounts = $roleDistribution->pluck('count', 'role')->toArray();

        return view('modules.admin.roles.index', compact('selectedSection', 'roles', 'allPermissions', 'roleDistribution', 'availableRoles', 'roleCounts'));
    }

    public function edit(string $role)
    {
        $role = strtolower($role);
        $availableRoles = $this->rolePermissionManager->getAvailableRoles();

        if (! in_array($role, $availableRoles)) {
            return redirect()->route('admin.roles.index')
                ->with('error', 'Invalid role: '.$role);
        }

        if (! $this->rolePermissionManager->roleExists($role)) {
            return redirect()->route('admin.roles.index')
                ->with('error', "Role '{$role}' does not exist in the system. Please run the seeder first.");
        }

        $this->rolePermissionManager->scanRoutesForPermissions();
        $rolePermissions = $this->rolePermissionManager->getRolePermissions($role);
        $allPermissions = $this->rolePermissionManager->getAllPermissions();

        return view('modules.admin.roles.form', compact('role', 'rolePermissions', 'allPermissions'));
    }

    public function update(UpdateRolePermissionsRequest $request, string $role)
    {
        $role = strtolower($role);
        $availableRoles = $this->rolePermissionManager->getAvailableRoles();

        if (! in_array($role, $availableRoles)) {
            return redirect()->route('admin.roles.index')
                ->with('error', 'Invalid role: '.$role);
        }

        try {
            DB::beginTransaction();

            $permissions = $request->input('permissions', []);
            $success = $this->rolePermissionManager->syncRolePermissions($role, $permissions);

            if (! $success) {
                throw new \Exception('Failed to sync permissions for role: '.$role);
            }

            DB::commit();

            return redirect()->route('admin.roles.index')
                ->with('success', 'Permissions updated successfully for role: '.ucfirst($role));
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role permission update failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    public function create()
    {
        $this->rolePermissionManager->scanRoutesForPermissions();
        $allPermissions = $this->rolePermissionManager->getAllPermissions();
        $availableRoles = $this->rolePermissionManager->getAvailableRoles();

        return view('modules.admin.roles.create', compact('allPermissions', 'availableRoles'));
    }

    public function store(StoreRoleRequest $request)
    {
        $roleName = strtolower(trim($request->input('role')));
        $status = $request->input('status', 'active');

        try {
            DB::beginTransaction();

            $permissions = $request->input('permissions', []);
            $success = $this->rolePermissionManager->createRole($roleName, $permissions, $status);

            if (! $success) {
                throw new \Exception('Failed to create role: '.$roleName);
            }

            DB::commit();

            return redirect()->route('admin.roles.index')
                ->with('success', "Role '{$roleName}' created successfully.");
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role creation failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    public function regenerate()
    {
        try {
            DB::beginTransaction();
            $this->rolePermissionManager->seedDefaults();
            DB::commit();

            return redirect()->route('admin.roles.index', ['section' => 'permissions'])
                ->with('success', 'Permissions regenerated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Permission regeneration failed: '.$e->getMessage());

            return back()->with('error', 'Unable to regenerate permissions. '.$e->getMessage());
        }
    }

    public function syncMatrix(Request $request)
    {
        $request->validate([
            'permissions' => 'nullable|array',
            'permissions.*' => 'nullable|array',
            'permissions.*.*' => 'nullable|string|max:100',
        ]);

        $availableRoles = $this->rolePermissionManager->getAvailableRoles();
        $permissionsByRole = $request->input('permissions', []);

        try {
            DB::beginTransaction();

            foreach ($permissionsByRole as $role => $permissionNames) {
                if ($role === 'admin' || ! in_array($role, $availableRoles, true)) {
                    continue;
                }

                $permissionNames = array_filter(array_map('trim', (array) $permissionNames), fn ($name) => $name !== '');
                $this->rolePermissionManager->syncRolePermissions($role, $permissionNames);
            }

            DB::commit();

            return redirect()->route('admin.roles.index', ['section' => 'permissions'])
                ->with('success', 'Role permissions updated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role matrix sync failed: '.$e->getMessage());

            return back()->with('error', 'Unable to update role permissions. '.$e->getMessage());
        }
    }

    public function destroy(string $role)
    {
        $role = strtolower($role);
        try {
            DB::beginTransaction();
            $success = $this->rolePermissionManager->deleteRole($role);

            if (! $success) {
                throw new \Exception('Failed to delete role: '.$role);
            }

            DB::commit();

            return redirect()->route('admin.roles.index')
                ->with('success', "Role '{$role}' deleted successfully.");
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role delete failed: '.$e->getMessage());

            return back()->with('error', 'Unable to delete role. '.$e->getMessage());
        }
    }

    public function editName(string $role)
    {
        $role = strtolower($role);
        $availableRoles = $this->rolePermissionManager->getAvailableRoles();

        if (! in_array($role, $availableRoles)) {
            return redirect()->route('admin.roles.index')
                ->with('error', 'Invalid role: '.$role);
        }

        if (! Role::where('name', $role)->where('guard_name', 'web')->exists()) {
            Role::create(['name' => $role, 'guard_name' => 'web', 'status' => 'active']);
        }

        if (in_array($role, ['admin', 'supervisor', 'user'])) {
            return redirect()->route('admin.roles.index')
                ->with('error', "Cannot rename protected role: {$role}");
        }

        $roleStatus = $this->rolePermissionManager->getRoleStatus($role);

        return view('modules.admin.roles.edit-name', compact('role', 'roleStatus'));
    }

    public function updateName(Request $request, string $role)
    {
        $role = strtolower($role);
        $request->validate([
            'role_name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $newName = strtolower(trim($request->input('role_name')));
        $newStatus = $request->input('status', 'active');

        if (in_array($role, ['admin', 'supervisor', 'user'])) {
            return redirect()->route('admin.roles.index')
                ->with('error', "Cannot rename protected role: {$role}");
        }

        if (Role::where('name', $newName)->where('guard_name', 'web')->exists()) {
            return back()->with('error', "Role '{$newName}' already exists.")->withInput();
        }

        $spatieRole = Role::where('name', $role)->where('guard_name', 'web')->firstOrFail();

        try {
            DB::beginTransaction();
            $spatieRole->update([
                'name' => $newName,
                'status' => $newStatus,
            ]);
            DB::commit();

            return redirect()->route('admin.roles.index')
                ->with('success', 'Role updated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role update failed: '.$e->getMessage());

            return back()->with('error', 'Unable to update role. '.$e->getMessage())->withInput();
        }
    }

    public function updateStatus(Request $request, string $role)
    {
        $role = strtolower($role);
        $request->validate([
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        if (in_array($role, ['admin', 'supervisor', 'user'])) {
            return back()->with('error', "Cannot change status of protected role: {$role}");
        }

        try {
            $this->rolePermissionManager->updateRoleStatus($role, $request->input('status'));

            return back()->with('success', "Role '{$role}' status updated to: ".ucfirst($request->input('status')));
        } catch (Throwable $e) {
            Log::error('Role status update failed: '.$e->getMessage());

            return back()->with('error', 'Unable to update role status. '.$e->getMessage());
        }
    }

    /**
     * Assign a Spatie role to a user.
     */
    public function assignRole(User $user)
    {
        $role = strtolower(trim(request()->input('role')));

        if ($user->role === 'admin') {
            return back()->with('error', 'Cannot change the role of an admin user.');
        }

        if ($role === 'admin') {
            return back()->with('error', 'Cannot assign the admin role to any user.');
        }

        if (! in_array($role, $this->rolePermissionManager->getAvailableRoles(), true)) {
            return back()->with('error', 'Invalid role selected.');
        }

        try {
            DB::beginTransaction();

            $adminProfileId = auth()->user()?->profile?->id;

            $this->rolePermissionManager->assignRole($user, $role, $adminProfileId);

            DB::commit();

            return redirect()->route('admin.users')->with('success', "User {$user->name} role changed to: ".ucfirst($role));
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role assignment failed: '.$e->getMessage());

            return redirect()->route('admin.users')->with('error', $e->getMessage());
        }
    }
}
