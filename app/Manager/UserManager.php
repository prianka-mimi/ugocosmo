<?php

namespace App\Manager;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserManager
{
    public function all(): Collection
    {
        return User::orderBy('sort_order', 'asc')->get();
    }

    public function paginate(?string $status = null, ?string $role = null, int $perPage = 20)
    {
        $query = User::query()->where('role', '!=', 'admin')->orderBy('id', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        if ($role) {
            $query->where('role', $role);
        }

        return $query->paginate($perPage);
    }

    public function create(array $data): User
    {
        $data['password'] = bcrypt($data['password'] ?? null);

        // A new user cannot be created with admin role
        if (isset($data['role']) && $data['role'] === 'admin') {
            $data['role'] = 'user';
        }

        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        // If the user is currently an admin, protect their role and status
        if ($user->role === 'admin') {
            unset($data['role']);
            $data['status'] = 'active';
        } else {
            // Non-admin users cannot be promoted to admin
            if (isset($data['role']) && $data['role'] === 'admin') {
                unset($data['role']);
            }
        }

        $user->update($data);

        return $user;
    }

    public function delete(User $user): bool
    {
        return $user->delete();
    }

    public function approve(User $user): bool
    {
        if ($user->role === 'admin') {
            return false;
        }

        $user->update(['status' => 'active', 'is_approved' => true]);

        return true;
    }

    public function reject(User $user): bool
    {
        if ($user->role === 'admin') {
            return false;
        }

        $user->update(['status' => 'inactive', 'is_approved' => false]);

        return true;
    }

    public function updateStatus(User $user, string $status): bool
    {
        // Admin status is always active — never change it
        if ($user->role === 'admin') {
            return false;
        }

        $user->update(['status' => $status]);

        return true;
    }
}
