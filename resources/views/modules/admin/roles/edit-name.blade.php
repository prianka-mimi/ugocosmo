@extends('layouts.dashboard')

@section('page-title', 'Edit Role: ' . ucfirst($role))

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Edit Role Name</h3>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="{{ route('admin.roles.index') }}" class="btn-cancel" style="text-decoration: none;">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="card" style="margin-bottom: 1rem; border-left: 4px solid #22c55e; padding: 1rem; background: #ecfdf5; color: #165a33;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="card" style="margin-bottom: 1rem; border-left: 4px solid #ef4444; padding: 1rem; background: #fef2f2; color: #991b1b;">{{ session('error') }}</div>
    @endif

    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.8125rem; color: #166534;">
        <i class="fa-solid fa-circle-info"></i>
        You can only rename custom roles. Protected roles like <strong>admin</strong>, <strong>supervisor</strong>, and <strong>user</strong> cannot be renamed.
    </div>

    <form method="POST" action="{{ route('admin.roles.update-name', $role) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="status" value="{{ $roleStatus ?? 'active' }}">

        <div class="form-group">
            <label for="role_name">Role Name</label>
            <input type="text" id="role_name" name="role_name" value="{{ ucfirst($role) }}" required
                   style="width: 100%; max-width: 400px; padding: 0.6rem 0.9rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.9375rem; font-family: inherit; outline: none; box-sizing: border-box;"
                   {{ in_array($role, ['admin', 'supervisor', 'user']) ? 'disabled' : '' }}>
            @if(in_array($role, ['admin', 'supervisor', 'user']))
                <p style="font-size: 0.8rem; color: #6b7280; margin-top: 0.35rem;">This is a protected role and cannot be renamed.</p>
            @endif
        </div>

        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status" style="width: 100%; max-width: 400px; padding: 0.6rem 0.9rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.9375rem; font-family: inherit; outline: none; background: #fff;" {{ in_array($role, ['admin', 'supervisor', 'user']) ? 'disabled' : '' }}>
                <option value="active" {{ ($roleStatus ?? 'active') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ ($roleStatus ?? 'active') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
            @if(in_array($role, ['admin', 'supervisor', 'user']))
                <p style="font-size: 0.8rem; color: #6b7280; margin-top: 0.35rem;">Protected roles always have active status.</p>
            @endif
        </div>

        <div style="text-align: right; padding-top: 0.5rem;">
            <a href="{{ route('admin.roles.index') }}" class="btn-cancel" style="text-decoration: none; margin-right: 0.5rem;">Cancel</a>
            @if(!in_array($role, ['admin', 'supervisor', 'user']))
                <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem; font-size: 0.875rem;">
                    <i class="fa-solid fa-floppy-disk"></i> Update
                </button>
            @endif
        </div>
    </form>
</div>
@endsection
