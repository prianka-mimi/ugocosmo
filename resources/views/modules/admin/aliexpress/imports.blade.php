@extends('layouts.dashboard')

@section('page-title', 'AliExpress — Import Logs')

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;">{{ session('error') }}</div>
@endif

<div class="card">
    <div class="card-header">
        <h3>Import Runs</h3>
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <a href="{{ route('admin.aliexpress.index') }}" class="btn-view"><i class="fa-solid fa-box-open"></i> Connections</a>
            <a href="{{ route('admin.aliexpress.sync-logs') }}" class="btn-view"><i class="fa-solid fa-rotate"></i> Sync Logs</a>
            <a href="{{ route('admin.aliexpress.api-logs') }}" class="btn-view"><i class="fa-solid fa-triangle-exclamation"></i> API Logs</a>
        </div>
    </div>
    <form method="GET" action="{{ route('admin.aliexpress.imports') }}" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem;">
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
        <label style="display:flex; align-items:center; gap:0.35rem; font-size:0.8125rem; color:#555; cursor:pointer;">
            <input type="checkbox" name="failed" value="1" onchange="this.form.submit()" {{ request('failed') ? 'checked' : '' }} style="accent-color:var(--primary);" />
            Failed / partial only
        </label>
        @if(request()->anyFilled(['status', 'account_id', 'failed']))
            <a href="{{ route('admin.aliexpress.imports') }}" class="btn-cancel" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.875rem;"><i class="fa-solid fa-rotate-left"></i></a>
        @endif
    </form>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th>Seller / Account</th>
                <th>Status</th>
                <th>Progress</th>
                <th>Imported</th>
                <th>Updated</th>
                <th>Skipped</th>
                <th>Failed</th>
                <th>Started</th>
                <th style="width:90px; text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($imports as $import)
            <tr>
                <td>#{{ $import->id }}</td>
                <td>
                    <div style="font-size:0.8125rem; font-weight:600;">{{ $import->account?->profile?->full_name ?? 'N/A' }}</div>
                    <div style="font-size:0.72rem; color:#999;">{{ $import->account?->account_name ?? ('Account #' . $import->aliexpress_account_id) }}</div>
                </td>
                <td>
                    @php
                        $colors = ['pending' => '#f59e0b', 'queued' => '#64748b', 'processing' => '#6366f1', 'completed' => '#16a34a', 'partial' => '#d97706', 'failed' => '#ef4444', 'cancelled' => '#6b7280'];
                        $color = $colors[$import->status] ?? '#555';
                    @endphp
                    <span class="badge" style="background:{{ $color }}15; color:{{ $color }}; text-transform:capitalize;">{{ $import->status }}</span>
                </td>
                <td style="font-size:0.8125rem;">{{ $import->progressPercentage() }}% ({{ $import->total_processed }}/{{ $import->total_products }})</td>
                <td style="font-size:0.8125rem;">{{ $import->imported_count }}</td>
                <td style="font-size:0.8125rem;">{{ $import->updated_count }}</td>
                <td style="font-size:0.8125rem;">{{ $import->skipped_count }}</td>
                <td style="font-size:0.8125rem; color:{{ $import->failed_count ? '#ef4444' : '#888' }};">{{ $import->failed_count }}</td>
                <td style="font-size:0.75rem; color:#888;">{{ $import->started_at?->format('M d, Y H:i') ?? '—' }}</td>
                <td style="text-align:right;">
                    <a href="{{ route('admin.aliexpress.imports.show', $import->id) }}" class="btn-view-icon" title="View"><i class="fa-solid fa-eye"></i></a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($imports->isEmpty())
        <p style="text-align:center; color:#999; padding:2rem; font-size:0.875rem;">No import runs found.</p>
    @endif
</div>

@if($imports->hasPages())
    <div style="margin-top:1rem;">{{ $imports->links() }}</div>
@endif
@endsection
