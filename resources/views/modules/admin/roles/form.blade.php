@extends('layouts.dashboard')

@section('page-title', 'Permissions: ' . ucfirst($role))

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Permissions for <strong>{{ ucfirst($role) }}</strong></h3>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="{{ route('admin.roles.index') }}" class="btn-cancel" style="text-decoration: none;">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.8125rem; color: #166534;">
        @if($role === 'admin')
        <i class="fa-solid fa-shield-halved"></i>
        <strong>Admin</strong> has <strong>all permissions</strong> by default. The settings below let you see what's available, but admin always has full access.
        @else
        <i class="fa-solid fa-toggle-on"></i>
        Toggle permissions <strong>ON</strong> to grant access, <strong>OFF</strong> to deny. Use "Select All" to grant all permissions in a section at once.
        @endif
    </div>

    <form method="POST" action="{{ route('admin.roles.update', $role) }}">
        @csrf
        @method('PUT')

        @foreach($allPermissions as $group => $perms)
            <div style="margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #f0f0f0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <h4 style="font-size: 0.875rem; font-weight: 600; color: #555; margin: 0;">{{ $group }}</h4>
                    @if($role !== 'admin')
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn-sm" style="background: #f0f0f0; border: none; padding: 0.25rem 0.6rem; border-radius: 0.375rem; font-size: 0.75rem; cursor: pointer; color: #555;" onclick="toggleGroup(this, true)">Select All</button>
                        <button type="button" class="btn-sm" style="background: #f0f0f0; border: none; padding: 0.25rem 0.6rem; border-radius: 0.375rem; font-size: 0.75rem; cursor: pointer; color: #555;" onclick="toggleGroup(this, false)">Clear All</button>
                    </div>
                    @endif
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 0.5rem;">
                    @foreach($perms as $key => $label)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 0.8rem; border-radius: 0.5rem; border: 1px solid {{ in_array($key, $rolePermissions) ? 'var(--primary)' : '#e5e5e5' }}; background: {{ in_array($key, $rolePermissions) ? 'var(--primary-light)' : '#fff' }}; transition: all 0.2s ease;">
                        <div style="flex: 1;">
                            <div style="font-size: 0.8125rem; font-weight: 500; color: #333;">{{ $label }}</div>
                            @if($role === 'admin')
                            <div style="font-size: 0.6875rem; color: #22c55e;"><i class="fa-solid fa-check"></i> Always active</div>
                            @endif
                        </div>
                        @if($role !== 'admin')
                        <label class="toggle-switch" style="flex-shrink: 0;">
                            <input type="checkbox" name="permissions[]" value="{{ $key }}"
                                   {{ in_array($key, $rolePermissions) ? 'checked' : '' }}
                                   onchange="this.closest('div').style.borderColor = this.checked ? 'var(--primary)' : '#e5e5e5'; this.closest('div').style.background = this.checked ? 'var(--primary-light)' : '#fff';">
                            <span class="toggle-slider"></span>
                        </label>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div style="text-align: right; padding-top: 0.5rem;">
            <button type="submit" class="btn-primary" style="padding: 0.65rem 2rem; font-size: 0.875rem;">
                <i class="fa-solid fa-floppy-disk"></i> Save Permissions
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
