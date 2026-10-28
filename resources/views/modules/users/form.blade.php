@extends('layouts.dashboard')

@section('page-title', $user ? 'Edit User' : 'Add User')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>{{ $user ? 'Edit User' : 'New User' }}</h3>
        <a href="{{ route('users.index') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.35rem;"></i> Back</a>
    </div>
    <form method="POST" action="{{ $user ? route('users.update', $user) : route('users.store') }}">
        @csrf
        @if($user)
            @method('PUT')
        @endif
        <div class="form-group">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" value="{{ old('name', $user->name ?? '') }}" required />
        </div>
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="{{ old('username', $user->username ?? '') }}" required />
        </div>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email', $user->email ?? '') }}" required />
        </div>
        @if(!$user)
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label for="password">Password</label>
                <div style="position: relative;">
                    <input type="password" id="password" name="password" required style="padding-right: 2.5rem;" />
                    <button type="button" onclick="togglePassword('password', this)" style="position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #888; padding: 0.25rem;">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label for="password_confirmation">Confirm Password</label>
                <div style="position: relative;">
                    <input type="password" id="password_confirmation" name="password_confirmation" required style="padding-right: 2.5rem;" />
                    <button type="button" onclick="togglePassword('password_confirmation', this)" style="position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #888; padding: 0.25rem;">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>
        </div>
        @endif
        <div class="form-group">
            <label for="role">Role</label>
            @if($user && $user->role === 'admin')
                <input type="hidden" name="role" value="admin" />
                <div style="padding: 0.5rem; background: #7c3aed20; color: #7c3aed; border-radius: 0.375rem; font-weight: 600; display: inline-block;">Admin (Protected)</div>
            @else
                <select id="role" name="role">
                    <option value="">-- Select Role --</option>
                    <option value="user" {{ old('role', $user->role ?? null) == 'user' ? 'selected' : '' }}>User</option>
                    <option value="supervisor" {{ old('role', $user->role ?? null) == 'supervisor' ? 'selected' : '' }}>Supervisor</option>
                </select>
            @endif
        </div>
        @if($user && $user->role !== 'admin')
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="active"   {{ old('status', $user->getRawOriginal('status') ?? 'pending') == 'active'    ? 'selected' : '' }}>Active</option>
                <option value="pending"  {{ old('status', $user->getRawOriginal('status') ?? 'pending') == 'pending'   ? 'selected' : '' }}>Pending</option>
                <option value="suspended" {{ old('status', $user->getRawOriginal('status') ?? 'pending') == 'suspended' ? 'selected' : '' }}>Suspended</option>
                <option value="blocked"  {{ old('status', $user->getRawOriginal('status') ?? 'pending') == 'blocked'   ? 'selected' : '' }}>Blocked</option>
                <option value="inactive" {{ old('status', $user->getRawOriginal('status') ?? 'pending') == 'inactive'  ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>
        @endif
        <div class="form-group">
            <label for="mobile_number">Mobile Number</label>
            <input type="text" id="mobile_number" name="mobile_number" value="{{ old('mobile_number', $user->mobile_number ?? '') }}" />
        </div>
        <div class="form-group">
            <label for="address_line_1">Address Line 1</label>
            <input type="text" id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $user->address_line_1 ?? '') }}" />
        </div>
        <div class="form-group">
            <label for="address_line_2">Address Line 2</label>
            <input type="text" id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $user->address_line_2 ?? '') }}" />
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label for="city">City</label>
                <input type="text" id="city" name="city" value="{{ old('city', $user->city ?? '') }}" />
            </div>
            <div class="form-group">
                <label for="postal_code">Postal Code</label>
                <input type="text" id="postal_code" name="postal_code" value="{{ old('postal_code', $user->postal_code ?? '') }}" />
            </div>
            <div class="form-group">
                <label for="country">Country</label>
                <input type="text" id="country" name="country" value="{{ old('country', $user->country ?? '') }}" />
            </div>
        </div>
        <div style="text-align: right;">
            <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem;">{{ $user ? 'Save Changes' : 'Create User' }}</button>
        </div>
    </form>
</div>

<script>
    function togglePassword(fieldId, btn) {
        const input = document.getElementById(fieldId);
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
</script>
@endsection