@extends('layouts.dashboard')

@section('page-title', 'Audit Logs')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Admin Action Logs</h3>
        <form method="GET" action="{{ route('admin.audit') }}" style="display: flex; gap: 0.5rem; align-items: center;">
            <select name="action" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff;">
                <option value="">All Actions</option>
                @foreach($actions as $action)
                <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>{{ str_replace('_', ' ', ucfirst($action)) }}</option>
                @endforeach
            </select>
            <select name="entity_type" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff;">
                <option value="">All Types</option>
                @foreach($entityTypes as $type)
                <option value="{{ $type }}" {{ request('entity_type') === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
            @if(request('action') || request('entity_type'))
                <a href="{{ route('admin.audit') }}" class="btn-cancel" style="text-decoration: none; padding: 0.4rem 0.75rem; font-size: 0.875rem;"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            @endif
        </form>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width: 50px;">#</th>
                <th>Admin</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Entity ID</th>
                <th>IP</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
            <tr>
                <td>{{ $log->id }}</td>
                <td>
                    <div style="font-size: 0.8125rem;">{{ $log->admin?->full_name ?? 'System' }}</div>
                </td>
                <td>
                    <span class="badge" style="background: #f0f0f0; color: #555; text-transform: capitalize;">
                        {{ str_replace('_', ' ', $log->action) }}
                    </span>
                </td>
                <td style="font-size: 0.8125rem;">{{ ucfirst($log->entity_type) }}</td>
                <td style="font-size: 0.8125rem; color: #888;">{{ $log->entity_id ?? '-' }}</td>
                <td style="font-size: 0.75rem; font-family: monospace; color: #888;">{{ $log->ip_address }}</td>
                <td style="font-size: 0.75rem; color: #888; white-space: nowrap;">{{ $log->created_at->format('d M Y H:i') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($logs->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No audit logs found.</p>
    @endif
</div>
@endsection
