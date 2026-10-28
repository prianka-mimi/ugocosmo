@extends('layouts.dashboard')

@section('page-title', 'AliExpress — API Logs')

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;">{{ session('error') }}</div>
@endif

<div class="card">
    <div class="card-header">
        <h3>Request / Response Log</h3>
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <a href="{{ route('admin.aliexpress.index') }}" class="btn-view"><i class="fa-solid fa-box-open"></i> Connections</a>
            <a href="{{ route('admin.aliexpress.imports') }}" class="btn-view"><i class="fa-solid fa-file-import"></i> Import Logs</a>
            <a href="{{ route('admin.aliexpress.sync-logs') }}" class="btn-view"><i class="fa-solid fa-rotate"></i> Sync Logs</a>
        </div>
    </div>

    {{-- Result tabs --}}
    <div style="display:flex; gap:0.35rem; margin-bottom:1rem; flex-wrap:wrap;">
        @php
            $tabs = ['all' => 'All Calls', 'failed' => 'Failed Requests', 'errors' => 'API Errors'];
        @endphp
        @foreach($tabs as $key => $label)
            <a href="{{ route('admin.aliexpress.api-logs', array_merge(request()->except(['result', 'page']), ['result' => $key])) }}"
               class="btn-cancel" style="text-decoration:none; padding:0.45rem 1rem; font-size:0.8125rem; {{ $result === $key ? 'background:var(--primary); color:#fff; border-color:var(--primary); font-weight:600;' : 'color:#555;' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.aliexpress.api-logs') }}" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem;">
        <input type="hidden" name="result" value="{{ $result }}" />
        <select name="method" onchange="this.form.submit()" style="padding:0.4rem 1.8rem 0.4rem 0.6rem; border:1px solid #ddd; border-radius:0.375rem; font-size:0.875rem; background:#fff; max-width:340px;">
            <option value="">All Methods</option>
            @foreach($methods as $m)
                <option value="{{ $m }}" {{ request('method') === $m ? 'selected' : '' }}>{{ $m }}</option>
            @endforeach
        </select>
        <select name="account_id" onchange="this.form.submit()" style="padding:0.4rem 1.8rem 0.4rem 0.6rem; border:1px solid #ddd; border-radius:0.375rem; font-size:0.875rem; background:#fff;">
            <option value="">All Accounts</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}" {{ request('account_id') == $acc->id ? 'selected' : '' }}>{{ $acc->account_name ?? ('Account #' . $acc->id) }}</option>
            @endforeach
        </select>
        @if(request()->anyFilled(['method', 'account_id']))
            <a href="{{ route('admin.aliexpress.api-logs', ['result' => $result]) }}" class="btn-cancel" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.875rem;"><i class="fa-solid fa-rotate-left"></i></a>
        @endif
    </form>

    <table id="dataTable">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th>Time</th>
                <th>Method</th>
                <th>Result</th>
                <th>HTTP</th>
                <th>Error Code</th>
                <th>Error / Message</th>
                <th>Seller</th>
                <th>Latency</th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
            <tr>
                <td>#{{ $log->id }}</td>
                <td style="font-size:0.72rem; color:#888; white-space:nowrap;">{{ $log->created_at?->format('M d, Y H:i:s') ?? '—' }}</td>
                <td style="font-size:0.75rem; word-break:break-all; max-width:220px;">{{ $log->method }}</td>
                <td>
                    @if($log->success)
                        <span class="badge" style="background:#f0fdf4; color:#16a34a;">OK</span>
                    @else
                        <span class="badge" style="background:#fef2f2; color:#dc2626;">Failed</span>
                    @endif
                </td>
                <td style="font-size:0.75rem;">{{ $log->http_status ?? '—' }}</td>
                <td style="font-size:0.72rem; color:{{ $log->error_code ? '#b91c1c' : '#999' }};">{{ $log->error_code ?? '—' }}</td>
                <td style="font-size:0.75rem; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $log->error_message }}">{{ $log->error_message ?? '—' }}</td>
                <td style="font-size:0.75rem;">{{ $log->account?->profile?->full_name ?? $log->account?->account_name ?? '—' }}</td>
                <td style="font-size:0.72rem; color:#888;">{{ $log->duration_ms !== null ? $log->duration_ms . ' ms' : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($logs->isEmpty())
        <p style="text-align:center; color:#999; padding:2rem; font-size:0.875rem;">No API calls found.</p>
    @endif
</div>

@if($logs->hasPages())
    <div style="margin-top:1rem;">{{ $logs->links() }}</div>
@endif
@endsection
