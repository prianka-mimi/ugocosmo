@extends('layouts.dashboard')

@section('page-title', 'Manage Users')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>All Users</h3>
        <form method="GET" action="{{ route('users.index') }}" style="display: flex; gap: 0.5rem; align-items: center;">
            <select name="status" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff; appearance: none; -webkit-appearance: none; background-image: none; background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 0.75rem;">
                <option value="">All Status</option>
                <option value="active" {{ ($statusFilter ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="pending" {{ ($statusFilter ?? '') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="suspended" {{ ($statusFilter ?? '') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                <option value="blocked" {{ ($statusFilter ?? '') === 'blocked' ? 'selected' : '' }}>Blocked</option>
                <option value="inactive" {{ ($statusFilter ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
            <select name="role" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff; appearance: none; -webkit-appearance: none; background-image: none; background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 0.75rem;">
                <option value="">All Roles</option>
                <option value="user" {{ ($roleFilter ?? '') === 'user' ? 'selected' : '' }}>User</option>
                <option value="supervisor" {{ ($roleFilter ?? '') === 'supervisor' ? 'selected' : '' }}>Supervisor</option>
            </select>
            @if($statusFilter ?? $roleFilter)
                <a href="{{ route('users.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.4rem 0.75rem; font-size: 0.875rem;"><i class="fa-solid fa-rotate-left"></i></a>
            @endif
            <a href="{{ route('users.create') }}" class="btn-primary" style="margin-left: auto; padding: 0.4rem 1rem; font-size: 0.875rem;"><i class="fa-solid fa-plus"></i> Add User</a>
        </form>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width: 50px;">SL</th>
                <th>User</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th style="width: 180px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $user)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                        @if($user->avatar)
                            <img src="{{ asset('storage/' . $user->avatar) }}" alt="" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;"/>
                        @else
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center;">
                                <i class="fa-solid fa-user" style="font-size: 0.7rem; color: #bbb;"></i>
                            </div>
                        @endif
                        <div>
                            <div style="font-weight: 500; font-size: 0.875rem;">{{ $user->name }}</div>
                            <div style="font-size: 0.75rem; color: #999;">{{ $user->username }}</div>
                        </div>
                    </div>
                </td>
                <td>{{ $user->email }}</td>
                <td>{{ $user->role ?? 'User' }}</td>
                <td><x-status-badge :status="$user->status" /></td>
                <td style="text-align: right; white-space: nowrap;">
                    @if($user->role !== 'admin')
                        <a href="{{ route('users.show', $user) }}" class="btn-view-icon" title="View"><i class="fa-solid fa-eye"></i></a>
                        <a href="{{ route('users.edit', $user) }}" class="btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                        @if($user->role === 'supervisor')
                            <form method="POST" action="{{ route('users.approve', $user) }}" style="display: inline;">
                                @csrf
                                <button type="button" class="btn-approve" onclick="var b=this; b.disabled=true; b.textContent='Approving...'; setTimeout(function(){ b.form.submit(); }, 100);" @if($user->getRawOriginal('status') === 'active') disabled style="opacity:0.5;cursor:not-allowed;" @endif>Approve</button>
                            </form>
                            <form method="POST" action="{{ route('users.reject', $user) }}" style="display: inline;">
                                @csrf
                                <button type="button" class="btn-reject" onclick="var b=this; b.disabled=true; b.textContent='Rejecting...'; setTimeout(function(){ b.form.submit(); }, 100);" @if($user->getRawOriginal('status') === 'inactive') disabled style="opacity:0.5;cursor:not-allowed;" @endif>Reject</button>
                            </form>
                        @endif
                        <button onclick="confirmDelete('user-delete-{{ $user->id }}')" class="btn-delete" title="Delete"><i class="fa-solid fa-trash"></i></button>
                        <form id="user-delete-{{ $user->id }}" action="{{ route('users.destroy', $user) }}" method="POST" style="display: none;">
                            @csrf
                            @method('DELETE')
                        </form>
                    @else
                        <span class="badge" style="background: #7c3aed20; color: #7c3aed;">Admin (Protected)</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($users->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No users found.</p>
    @endif
</div>
@endsection