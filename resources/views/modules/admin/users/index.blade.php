@extends('layouts.dashboard')

@section('page-title', 'Admin - User Management')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>All Users</h3>
        <form method="GET" action="{{ route('admin.users') }}" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <select name="status" onchange="this.form.submit()" style="padding: 0.4rem 2rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff; -webkit-appearance: none; -moz-appearance: none; appearance: none;">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
            <select name="role" onchange="this.form.submit()" style="padding: 0.4rem 2rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff; -webkit-appearance: none; -moz-appearance: none; appearance: none;">
                <option value="">All Roles</option>
                @foreach($allRoles as $r)
                    @if($r !== 'admin')
                        <option value="{{ $r }}" {{ request('role') === $r ? 'selected' : '' }}>{{ ucfirst($r) }}</option>
                    @endif
                @endforeach
            </select>
            @if(request()->anyFilled(['role', 'status', 'search']))
                <a href="{{ route('admin.users') }}" class="btn-cancel" style="text-decoration: none; padding: 0.4rem 0.75rem; font-size: 0.875rem;" title="Clear filters"><i class="fa-solid fa-rotate-left"></i></a>
            @endif
        </form>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th style="text-align: center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $user)
            <tr>
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
                            <div style="font-size: 0.75rem; color: #999;">{{ '@' . $user->username }}</div>
                        </div>
                    </div>
                </td>
                <td style="font-size: 0.8125rem;">{{ $user->email }}</td>
                <td>
                    @php
                        $roleColors = ['admin' => '#7c3aed', 'supervisor' => '#0891b2', 'user' => '#555'];
                    @endphp
                    <span class="badge" style="background: {{ $roleColors[$user->role] ?? '#555' }}20; color: {{ $roleColors[$user->role] ?? '#555' }}; text-transform: capitalize;">
                        {{ $user->role }}
                    </span>
                </td>
                <td><x-status-badge :status="$user->status" /></td>
                <td style="text-align: right; white-space: nowrap;">
                    <a href="{{ route('admin.users.show', $user) }}" class="btn-view-icon" title="View"><i class="fa-solid fa-eye"></i></a>
                    <a href="{{ route('users.edit', $user) }}" class="btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>

                     @if($user->role !== 'admin' && $user->role !== 'user')
                        @canPermission('users.edit')
                        @if($user->is_approved)
                            <form method="POST" action="{{ route('users.approve', $user) }}" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn-approve" disabled style="opacity: 0.5; cursor: not-allowed;" title="Already approved"><i class="fa-solid fa-check"></i> Approve</button>
                            </form>
                            <form method="POST" action="{{ route('users.reject', $user) }}" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn-reject" title="Reject"><i class="fa-solid fa-xmark"></i> Reject</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('users.approve', $user) }}" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn-approve" title="Approve"><i class="fa-solid fa-check"></i> Approve</button>
                            </form>
                            <form method="POST" action="{{ route('users.reject', $user) }}" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn-reject" disabled style="opacity: 0.5; cursor: not-allowed;" title="Not approved yet"><i class="fa-solid fa-xmark"></i> Reject</button>
                            </form>
                        @endif
                        @endcanPermission
                    @endif

                    @canPermission('roles.assign')
                    @if($user->role !== 'admin')
                    <form method="POST" action="{{ route('admin.roles.assign', $user) }}" style="display: inline-block;" onsubmit="return confirm('Change role to ' + this.role.value + '?');">
                        @csrf
                        <select name="role" onchange="this.form.submit()" style="padding: 0.35rem 0.5rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.8125rem; background: #fff;">
                            @foreach($allRoles as $r)
                                @if($r !== 'admin')
                                    <option value="{{ $r }}" {{ $user->role === $r ? 'selected' : '' }}>{{ ucfirst($r) }}</option>
                                @endif
                            @endforeach
                        </select>
                    </form>
                    @else
                    <span class="badge" style="background: #7c3aed20; color: #7c3aed; text-transform: capitalize;">Admin</span>
                    @endif
                    @endcanPermission

                    @canPermission('users.delete')
                    @if($user->role !== 'admin')
                    <button onclick="confirmDelete('user-delete-{{ $user->id }}')" class="btn-delete" title="Delete"><i class="fa-solid fa-trash"></i></button>
                    <form id="user-delete-{{ $user->id }}" action="{{ route('admin.users.destroy', $user) }}" method="POST" style="display: none;">
                        @csrf
                        @method('DELETE')
                    </form>
                    @endif
                    @endcanPermission
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
