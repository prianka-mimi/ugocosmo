@extends('layouts.dashboard')

@section('page-title', 'AliExpress — Import #' . $import->id)

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;">{{ session('error') }}</div>
@endif

<a href="{{ route('admin.aliexpress.imports') }}" class="btn-cancel" style="text-decoration:none; margin-bottom:1rem; display:inline-block;"><i class="fa-solid fa-arrow-left"></i> Back to import logs</a>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3>Import #{{ $import->id }}</h3>
        @php
            $colors = ['pending' => '#f59e0b', 'queued' => '#64748b', 'processing' => '#6366f1', 'completed' => '#16a34a', 'partial' => '#d97706', 'failed' => '#ef4444', 'cancelled' => '#6b7280'];
            $color = $colors[$import->status] ?? '#555';
        @endphp
        <span class="badge" style="background:{{ $color }}15; color:{{ $color }}; text-transform:capitalize;">{{ $import->status }}</span>
    </div>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:1rem;">
        <div>
            <div style="font-size:0.72rem; color:#888; font-weight:600; text-transform:uppercase;">Seller</div>
            <div style="font-size:0.875rem; margin-top:0.25rem;">{{ $import->account?->profile?->full_name ?? 'N/A' }}</div>
            <div style="font-size:0.72rem; color:#999;">{{ $import->account?->account_name ?? '' }}</div>
        </div>
        <div>
            <div style="font-size:0.72rem; color:#888; font-weight:600; text-transform:uppercase;">Progress</div>
            <div style="font-size:0.875rem; margin-top:0.25rem;">{{ $import->progressPercentage() }}% ({{ $import->total_processed }}/{{ $import->total_products }})</div>
        </div>
        <div>
            <div style="font-size:0.72rem; color:#888; font-weight:600; text-transform:uppercase;">Imported / Updated</div>
            <div style="font-size:0.875rem; margin-top:0.25rem;">{{ $import->imported_count }} / {{ $import->updated_count }}</div>
        </div>
        <div>
            <div style="font-size:0.72rem; color:#888; font-weight:600; text-transform:uppercase;">Skipped / Failed</div>
            <div style="font-size:0.875rem; margin-top:0.25rem;">{{ $import->skipped_count }} / <span style="color:{{ $import->failed_count ? '#ef4444' : '#333' }};">{{ $import->failed_count }}</span></div>
        </div>
        <div>
            <div style="font-size:0.72rem; color:#888; font-weight:600; text-transform:uppercase;">Started / Completed</div>
            <div style="font-size:0.8125rem; margin-top:0.25rem;">{{ $import->started_at?->format('M d, Y H:i') ?? '—' }}<br>{{ $import->completed_at?->format('M d, Y H:i') ?? '—' }}</div>
        </div>
    </div>
    @if($import->error_message)
        <div style="margin-top:1rem; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:0.6rem 0.75rem; border-radius:0.5rem; font-size:0.8125rem;">
            <strong>Error:</strong> {{ $import->error_message }}
        </div>
    @endif
</div>

<div class="card">
    <div class="card-header">
        <h3>Per-Product Logs ({{ $import->logs->count() }})</h3>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th>Product</th>
                <th>AliExpress ID</th>
                <th>Action</th>
                <th>Reason / Error</th>
                <th>When</th>
            </tr>
        </thead>
        <tbody>
            @foreach($import->logs as $log)
            <tr>
                <td>#{{ $log->id }}</td>
                <td style="font-size:0.8125rem;">{{ $log->product_title ?? '—' }}</td>
                <td style="font-size:0.75rem; color:#888;">{{ $log->aliexpress_product_id ?? '—' }}</td>
                <td>
                    @php
                        $actionColors = ['imported' => '#16a34a', 'updated' => '#0891b2', 'skipped' => '#6b7280', 'failed' => '#ef4444'];
                        $actionColor = $actionColors[$log->action] ?? '#555';
                    @endphp
                    <span class="badge" style="background:{{ $actionColor }}15; color:{{ $actionColor }}; text-transform:capitalize;">{{ $log->action }}</span>
                </td>
                <td style="font-size:0.75rem; max-width:280px; color:#555;" title="{{ $log->reason }} {{ $log->error_message }}">{{ $log->reason ?? $log->error_message ?? '—' }}</td>
                <td style="font-size:0.72rem; color:#888;">{{ $log->created_at?->format('M d, Y H:i') ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($import->logs->isEmpty())
        <p style="text-align:center; color:#999; padding:2rem; font-size:0.875rem;">No per-product logs recorded.</p>
    @endif
</div>
@endsection
