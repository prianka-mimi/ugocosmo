<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Manager\RolePermissionManager;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminUserController extends Controller
{
    protected RolePermissionManager $rolePermissionManager;

    public function __construct(RolePermissionManager $rolePermissionManager)
    {
        $this->rolePermissionManager = $rolePermissionManager;
    }

    public function index()
    {
        $query = User::where('role', '!=', 'admin')->with('profile');

        if ($role = request('role')) {
            $query->where('role', $role);
        }
        if ($status = request('status')) {
            $query->where('status', $status);
        }
        if ($search = request('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }
        if (request()->has('is_verified')) {
            $query->where('is_verified', filter_var(request('is_verified'), FILTER_VALIDATE_BOOLEAN));
        }

        $users = $query->orderBy('created_at', 'desc')->get();
        $allRoles = $this->rolePermissionManager->getAvailableRoles();

        return view('modules.admin.users.index', compact('users', 'allRoles'));
    }

    public function show(User $user)
    {
        $user->load([
            'profile.posts',
            'profile.products',
            'profile.services',
            'profile.jobs',
            'profile.events',
            'profile.bookings.service',
            'profile.jobApplications.job',
            'profile.orders.items.product',
            'profile.tickets.event',
            'subscriptions' => function ($q) {
                $q->latest()->limit(5);
            },
        ]);

        return view('modules.admin.users.show', compact('user'));
    }

    public function suspend(User $user)
    {
        try {
            DB::beginTransaction();
            if ($user->role === 'admin') {
                throw new \Exception('Cannot suspend an admin user');
            }
            $oldStatus = $user->status;
            $user->update(['status' => 'suspended', 'is_approved' => false]);
            $this->logAudit('user_suspended', 'user', $user->id, ['old_status' => $oldStatus, 'new_status' => 'suspended']);
            DB::commit();

            return back()->with('success', "User {$user->name} suspended successfully.");
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function activate(User $user)
    {
        try {
            DB::beginTransaction();
            $oldStatus = $user->status;
            $user->update(['status' => 'active', 'is_approved' => true]);
            $this->logAudit('user_activated', 'user', $user->id, ['old_status' => $oldStatus, 'new_status' => 'active']);
            DB::commit();

            return back()->with('success', "User {$user->name} activated successfully.");
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function verify(User $user)
    {
        try {
            DB::beginTransaction();
            $user->update(['is_verified' => true, 'verified_at' => now()]);
            $this->logAudit('user_verified', 'user', $user->id, ['is_verified' => true]);
            DB::commit();

            return back()->with('success', "User {$user->name} verified successfully.");
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(User $user)
    {
        try {
            DB::beginTransaction();
            if ($user->role === 'admin') {
                throw new \Exception('Cannot delete an admin user');
            }
            $this->logAudit('user_deleted', 'user', $user->id, ['email' => $user->email, 'name' => $user->name]);
            $user->delete();
            DB::commit();

            return redirect()->route('admin.users')->with('success', "User {$user->name} deleted successfully.");
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    protected function logAudit(string $action, string $entityType, ?int $entityId, array $metadata): void
    {
        try {
            AdminAuditLog::create([
                'admin_id' => auth()->user()?->profile?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'new_value' => $metadata,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            logger()->error('Failed to log audit: '.$e->getMessage());
        }
    }
}
