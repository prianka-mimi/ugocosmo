@extends('layouts.dashboard')

@section('page-title', 'AliExpress — Connection #' . $account->id)

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;">{{ session('error') }}</div>
@endif

<a href="{{ route('admin.aliexpress.index') }}" class="btn-cancel" style="text-decoration:none; margin-bottom:1rem; display:inline-block;"><i class="fa-solid fa-arrow-left"></i> Back to connections</a>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
    {{-- Seller card --}}
    <div class="card">
        <div class="card-header"><h3>Seller</h3></div>
        <div style="font-size:0.95rem; font-weight:600;">{{ $account->profile?->full_name ?? $account->user?->name ?? 'N/A' }}</div>
        <div style="font-size:0.8125rem; color:#888; margin-top:0.25rem;">{{ $account->profile?->username ?? '' }}</div>
        <div style="font-size:0.8125rem; color:#555; margin-top:0.5rem;">{{ $account->user?->email ?? '—' }}</div>
        <div style="font-size:0.75rem; color:#999; margin-top:0.5rem;">
            @if($account->profile)
                <a href="{{ route('admin.users.show', $account->user_id) }}" style="color:var(--primary);">View user profile →</a>
            @endif
        </div>
    </div>

    {{-- Connection card --}}
    <div class="card">
        <div class="card-header"><h3>Connection</h3></div>
        <table style="width:100%; font-size:0.8125rem;">
            <tr><td style="color:#888; padding:0.35rem 0;">Account name</td><td style="text-align:right;">{{ $account->account_name ?? '—' }}</td></tr>
            <tr><td style="color:#888; padding:0.35rem 0;">Member ID</td><td style="text-align:right;">{{ $account->aliexpress_member_id ?? '—' }}</td></tr>
            <tr><td style="color:#888; padding:0.35rem 0;">Region</td><td style="text-align:right; text-transform:uppercase;">{{ $account->region }}</td></tr>
            <tr><td style="color:#888; padding:0.35rem 0;">Status</td>
                <td style="text-align:right;">
                    @if($account->status === 'active')
                        <span class="badge" style="background:#f0fdf4; color:#166534;">Active</span>
                    @else
                        <span class="badge" style="background:#fef2f2; color:#b91c1c;">Disabled</span>
                    @endif
                </td>
            </tr>
            <tr><td style="color:#888; padding:0.35rem 0;">Connected</td><td style="text-align:right;">{{ $account->connected_at?->format('M d, Y H:i') ?? '—' }}</td></tr>
            <tr><td style="color:#888; padding:0.35rem 0;">Last sync</td><td style="text-align:right;">{{ $account->last_sync_at?->format('M d, Y H:i') ?? '—' }}</td></tr>
        </table>
    </div>

    {{-- Token health card --}}
    <div class="card">
        <div class="card-header"><h3>Token Health</h3></div>
        <table style="width:100%; font-size:0.8125rem;">
            <tr><td style="color:#888; padding:0.35rem 0;">Access token</td>
                <td style="text-align:right;">
                    @if($account->isTokenExpired())
                        <span class="badge" style="background:#fef2f2; color:#dc2626;">Expired</span>
                    @else
                        <span class="badge" style="background:#f0fdf4; color:#16a34a;">Valid</span>
                    @endif
                </td>
            </tr>
            <tr><td style="color:#888; padding:0.35rem 0;">Expires</td><td style="text-align:right;">{{ $account->token_expires_at?->format('M d, Y H:i') ?? '—' }}</td></tr>
            <tr><td style="color:#888; padding:0.35rem 0;">Refresh token</td>
                <td style="text-align:right;">
                    @if($account->isRefreshTokenExpired())
                        <span class="badge" style="background:#fef2f2; color:#dc2626;">Expired</span>
                    @else
                        <span class="badge" style="background:#f0fdf4; color:#16a34a;">Valid</span>
                    @endif
                </td>
            </tr>
            <tr><td style="color:#888; padding:0.35rem 0;">Overall</td>
                <td style="text-align:right;">
                    @if($account->needsReconnect())
                        <span class="badge" style="background:#fef2f2; color:#b91c1c;">Needs reconnect</span>
                    @else
                        <span class="badge" style="background:#f0fdf4; color:#166534;">Healthy</span>
                    @endif
                </td>
            </tr>
        </table>
        @if($account->last_error)
            <div style="margin-top:0.75rem; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:0.6rem 0.75rem; border-radius:0.5rem; font-size:0.75rem;">
                <strong>Last error:</strong> {{ $account->last_error }}
            </div>
        @endif
    </div>
</div>

{{-- Actions --}}
@if($account->status === 'active')
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header"><h3>Actions</h3></div>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <form method="POST" action="{{ route('admin.aliexpress.refresh-token', $account->id) }}" class="swal-confirm" data-swal-title="Refresh token now?" data-swal-text="Force an immediate token refresh against AliExpress (uses the stored refresh token)." data-swal-confirm-text="Yes, refresh" style="display:inline;">
                @csrf
                <button type="submit" class="btn-view"><i class="fa-solid fa-arrows-rotate"></i> Refresh Token (Reconnect)</button>
            </form>
            <form method="POST" action="{{ route('admin.aliexpress.disable', $account->id) }}" class="swal-confirm" data-swal-title="Disable connection?" data-swal-text="The seller will no longer be able to use this connection. Imported products are preserved." data-swal-confirm-text="Yes, disable" style="display:inline;">
                @csrf
                <button type="submit" class="btn-delete"><i class="fa-solid fa-ban"></i> Disable Connection</button>
            </form>
        </div>
    </div>
@else
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header"><h3>Actions</h3></div>
        <form method="POST" action="{{ route('admin.aliexpress.reactivate', $account->id) }}" class="swal-confirm" data-swal-title="Re-enable connection?" data-swal-text="This will reactivate the seller's AliExpress connection." data-swal-confirm-text="Yes, re-enable" style="display:inline;">
            @csrf
            <button type="submit" class="btn-approve"><i class="fa-solid fa-rotate-right"></i> Re-enable Connection</button>
        </form>
    </div>
@endif

{{-- Recent imports --}}
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3>Recent Imports</h3>
        <a href="{{ route('admin.aliexpress.imports', ['account_id' => $account->id]) }}" class="btn-view"><i class="fa-solid fa-file-import"></i> View All</a>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th>Status</th>
                <th>Progress</th>
                <th>Imported</th>
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
                    @php
                        $colors = ['pending' => '#f59e0b', 'queued' => '#64748b', 'processing' => '#6366f1', 'completed' => '#16a34a', 'partial' => '#d97706', 'failed' => '#ef4444', 'cancelled' => '#6b7280'];
                        $color = $colors[$import->status] ?? '#555';
                    @endphp
                    <span class="badge" style="background:{{ $color }}15; color:{{ $color }}; text-transform:capitalize;">{{ $import->status }}</span>
                </td>
                <td style="font-size:0.8125rem;">{{ $import->progressPercentage() }}% ({{ $import->total_processed }}/{{ $import->total_products }})</td>
                <td style="font-size:0.8125rem;">{{ $import->imported_count }}</td>
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
        <p style="text-align:center; color:#999; padding:1.5rem; font-size:0.875rem;">No imports yet.</p>
    @endif
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; align-items:start;">
    {{-- Recent API logs --}}
    <div class="card">
        <div class="card-header">
            <h3>Recent API Calls</h3>
            <a href="{{ route('admin.aliexpress.api-logs', ['account_id' => $account->id]) }}" class="btn-view"><i class="fa-solid fa-triangle-exclamation"></i> View All</a>
        </div>
        <table id="dataTable" data-hide-filter="1">
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Result</th>
                    <th>When</th>
                </tr>
            </thead>
            <tbody>
                @foreach($apiLogs as $log)
                <tr>
                    <td style="font-size:0.75rem; word-break:break-all;">{{ $log->method }}</td>
                    <td style="font-size:0.75rem;">{{ $log->http_status ?? '—' }}</td>
                    <td>
                        @if($log->success)
                            <span class="badge" style="background:#f0fdf4; color:#16a34a;">OK</span>
                        @else
                            <span class="badge" style="background:#fef2f2; color:#dc2626;" title="{{ $log->error_message }}">Failed</span>
                        @endif
                    </td>
                    <td style="font-size:0.7rem; color:#888;">{{ $log->created_at?->format('M d H:i') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($apiLogs->isEmpty())
            <p style="text-align:center; color:#999; padding:1.5rem; font-size:0.875rem;">No API calls logged yet.</p>
        @endif
    </div>

    {{-- Recent sync logs --}}
    <div class="card">
        <div class="card-header">
            <h3>Recent Sync Runs</h3>
            <a href="{{ route('admin.aliexpress.sync-logs', ['account_id' => $account->id]) }}" class="btn-view"><i class="fa-solid fa-rotate"></i> View All</a>
        </div>
        <table id="dataTable" data-hide-filter="1">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Message</th>
                    <th>When</th>
                </tr>
            </thead>
            <tbody>
                @foreach($syncLogs as $log)
                <tr>
                    <td style="font-size:0.75rem; text-transform:capitalize;">{{ str_replace('_', ' ', $log->type) }}</td>
                    <td>
                        @php
                            $syncColors = ['started' => '#f59e0b', 'completed' => '#16a34a', 'failed' => '#ef4444'];
                            $syncColor = $syncColors[$log->status] ?? '#555';
                        @endphp
                        <span class="badge" style="background:{{ $syncColor }}15; color:{{ $syncColor }}; text-transform:capitalize;">{{ $log->status }}</span>
                    </td>
                    <td style="font-size:0.75rem; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $log->message }}">{{ $log->message ?? '—' }}</td>
                    <td style="font-size:0.7rem; color:#888;">{{ $log->completed_at?->format('M d H:i') ?? $log->created_at?->format('M d H:i') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($syncLogs->isEmpty())
            <p style="text-align:center; color:#999; padding:1.5rem; font-size:0.875rem;">No sync runs yet.</p>
        @endif
    </div>
</div>
@endsection
