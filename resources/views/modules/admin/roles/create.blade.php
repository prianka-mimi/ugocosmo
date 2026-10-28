@extends('layouts.dashboard')

@section('page-title', 'Create New Role')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Create New Role</h3>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="{{ route('admin.roles.index') }}" class="btn-cancel" style="text-decoration: none;">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.roles.store') }}">
        @csrf

        <div style="margin-bottom: 1.5rem;">
            <label for="role" style="display: block; font-size: 0.875rem; font-weight: 600; color: #333; margin-bottom: 0.5rem;">
                Role Name <span style="color: #ef4444;">*</span>
            </label>
            <input
                type="text"
                id="role"
                name="role"
                value="{{ old('role') }}"
                placeholder="e.g. moderator, editor, support"
                style="width: 100%; max-width: 400px; padding: 0.6rem 0.8rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.875rem;"
                required
            />
            @error('role')
                <div style="color: #ef4444; font-size: 0.75rem; margin-top: 0.25rem;">{{ $message }}</div>
            @enderror
        </div>

        <div style="margin-bottom: 1.5rem;">
            <label for="status" style="display: block; font-size: 0.875rem; font-weight: 600; color: #333; margin-bottom: 0.5rem;">
                Status
            </label>
            <select id="status" name="status" style="width: 100%; max-width: 400px; padding: 0.6rem 0.8rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.875rem; background: #fff;">
                <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
            <p style="font-size: 0.75rem; color: #6b7280; margin-top: 0.35rem;">Inactive roles cannot be assigned to new users.</p>
        </div>

        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.8125rem; color: #166534;">
            <i class="fa-solid fa-toggle-on"></i>
            Toggle permissions <strong>ON</strong> to grant access, <strong>OFF</strong> to deny.
        </div>

        @foreach($allPermissions as $group => $perms)
            <div style="margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #f0f0f0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <h4 style="font-size: 0.875rem; font-weight: 600; color: #555; margin: 0;">{{ $group }}</h4>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn-sm" style="background: #f0f0f0; border: none; padding: 0.25rem 0.6rem; border-radius: 0.375rem; font-size: 0.75rem; cursor: pointer; color: #555;" onclick="toggleGroup(this, true)">Select All</button>
                        <button type="button" class="btn-sm" style="background: #f0f0f0; border: none; padding: 0.25rem 0.6rem; border-radius: 0.375rem; font-size: 0.75rem; cursor: pointer; color: #555;" onclick="toggleGroup(this, false)">Clear All</button>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 0.5rem;">
                    @foreach($perms as $key => $label)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 0.8rem; border-radius: 0.5rem; border: 1px solid #e5e5e5; background: #fff; transition: all 0.2s ease;">
                        <div style="flex: 1;">
                            <div style="font-size: 0.8125rem; font-weight: 500; color: #333;">{{ $label }}</div>
                            <div style="font-size: 0.6875rem; color: #999;">{{ $key }}</div>
                        </div>
                        <label class="toggle-switch" style="flex-shrink: 0;">
                            <input type="checkbox" name="permissions[]" value="{{ $key }}"
                                   onchange="this.closest('div').style.borderColor = this.checked ? 'var(--primary)' : '#e5e5e5'; this.closest('div').style.background = this.checked ? 'var(--primary-light)' : '#fff';">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div style="text-align: right; padding-top: 0.5rem;">
            <button type="submit" class="btn-primary" style="padding: 0.65rem 2rem; font-size: 0.875rem;">
                <i class="fa-solid fa-plus"></i> Create Role
            </button>
        </div>
    </form>
</div>

<script>
function toggleGroup(btn, check) {
    var container = btn.closest('div').parentElement.nextElementSibling;
    if (!container) return;
    var toggles = container.querySelectorAll('input[type="checkbox"]');
    toggles.forEach(function(cb) {
        cb.checked = check;
        var card = cb.closest('div');
        if (card) {
            card.style.borderColor = check ? 'var(--primary)' : '#e5e5e5';
            card.style.background = check ? 'var(--primary-light)' : '#fff';
        }
    });
}
</script>
@endsection
