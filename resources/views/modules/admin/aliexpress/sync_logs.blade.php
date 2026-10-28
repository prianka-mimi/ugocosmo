@extends('layouts.dashboard')

@section('page-title', 'AliExpress — Sync Logs')

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;">{{ session('error') }}</div>
@endif

<div class="card">
    <div class="card-header">
        <h3>Background Sync Runs</h3>
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <a href="{{ route('admin.aliexpress.index') }}" class="btn-view"><i class="fa-solid fa-box-open"></i> Connections</a>
            <a href="{{ route('admin.aliexpress.imports') }}" class="btn-view"><i class="fa-solid fa-file-import"></i> Import Logs</a>
            <a href="{{ route('admin.aliexpress.api-logs') }}" class="btn-view"><i class="fa-solid fa-triangle-exclamation"></i> API Logs</a>
        </div>
    </div>
    <form method="GET" action="{{ route('admin.aliexpress.sync-logs') }}" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem;">
        <select name="type" onchange="this.form.submit()" style="padding:0.4rem 1.8rem 0.4rem 0.6rem; border:1px solid #ddd; border-radius:0.375rem; font-size:0.875rem; background:#fff;">
            <option value="">All Types</option>
            @foreach($types as $t)
                <option value="{{ $t }}" {{ request('type') === $t ? 'selected' : '' }}>{{ ucwords(str_replace('_', ' ', $t)) }}</option>
            @endforeach
        </select>
        <select name="status" onchange="this.form.submit()" style="padding:0.4rem 1.8rem 0.4rem 0.6rem; border:1px solid #ddd; border-radius:0.375rem; font-size:0.875rem; background:#fff;">
            <option value="">All Statuses</option>
            @foreach($statuses as $s)
                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <select name="account_id" onchange="this.form.submit()" style="padding:0.4rem 1.8rem 0.4rem 0.6rem; border:1px solid #ddd; border-radius:0.375rem; font-size:0.875rem; background:#fff;">
            <option value="">All Accounts</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}" {{ request('account_id') == $acc->id ? 'selected' : '' }}>{{ $acc->account_name ?? ('Account #' . $acc->id) }}</option>
            @endforeach
        </select>
        @if(request()->anyFilled(['type', 'status', 'account_id']))
            <a href="{{ route('admin.aliexpress.sync-logs') }}" class="btn-cancel" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.875rem;"><i class="fa-solid fa-rotate-left"></i></a>
        @endif
    </form>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th>Type</th>
                <th>Status</th>
                <th>Seller / Account</th>
                <th>Message</th>
                <th>Started</th>
                <th>Completed</th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
            <tr>
                <td>#{{ $log->id }}</td>
                <td style="font-size:0.8125rem; text-transform:capitalize;">{{ str_replace('_', ' ', $log->type) }}</td>
                <td>
                    @php
                        $syncColors = ['started' => '#f59e0b', 'completed' => '#16a34a', 'failed' => '#ef4444'];
                        $syncColor = $syncColors[$log->status] ?? '#555';
                    @endphp
                    <span class="badge" style="background:{{ $syncColor }}15; color:{{ $syncColor }}; text-transform:capitalize;">{{ $log->status }}</span>
                </td>
                <td>
                    <div style="font-size:0.8125rem;">{{ $log->account?->profile?->full_name ?? 'N/A' }}</div>
                    <div style="font-size:0.72rem; color:#999;">{{ $log->account?->account_name ?? '—' }}</div>
                </td>
                <td style="font-size:0.75rem; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $log->message }}">{{ $log->message ?? '—' }}</td>
                <td style="font-size:0.75rem; color:#888;">{{ $log->started_at?->format('M d, Y H:i') ?? $log->created_at?->format('M d, Y H:i') }}</td>
                <td style="font-size:0.75rem; color:#888;">{{ $log->completed_at?->format('M d, Y H:i') ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($logs->isEmpty())
        <p style="text-align:center; color:#999; padding:2rem; font-size:0.875rem;">No sync runs found.</p>
    @endif
</div>

@if($logs->hasPages())
    <div style="margin-top:1rem;">{{ $logs->links() }}</div>
@endif
@endsection
