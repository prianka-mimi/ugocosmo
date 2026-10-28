@extends('layouts.dashboard')

@section('page-title', 'AliExpress — Connected Sellers')

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;">{{ session('error') }}</div>
@endif

{{-- ─── Overview cards ─────────────────────────────────────────── --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Total Connections</div>
        <div style="font-size:1.75rem; font-weight:700; margin-top:0.35rem;">{{ $stats['total'] }}</div>
    </div>
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Active</div>
        <div style="font-size:1.75rem; font-weight:700; margin-top:0.35rem; color:#16a34a;">{{ $stats['active'] }}</div>
    </div>
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Disabled</div>
        <div style="font-size:1.75rem; font-weight:700; margin-top:0.35rem; color:#dc2626;">{{ $stats['disabled'] }}</div>
    </div>
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Tokens Expiring ≤72h</div>
        <div style="font-size:1.75rem; font-weight:700; margin-top:0.35rem; color:#d97706;">{{ $stats['expiring_soon'] }}</div>
    </div>
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Failed Imports (7d)</div>
        <div style="font-size:1.75rem; font-weight:700; margin-top:0.35rem; color:#ef4444;">{{ $stats['failed_imports_7d'] }}</div>
    </div>
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">API Errors (7d)</div>
        <div style="font-size:1.75rem; font-weight:700; margin-top:0.35rem; color:#ef4444;">{{ $stats['api_errors_7d'] }}</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Connected Sellers</h3>
        <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
            <a href="{{ route('admin.aliexpress.imports') }}" class="btn-view"><i class="fa-solid fa-file-import"></i> Import Logs</a>
            <a href="{{ route('admin.aliexpress.sync-logs') }}" class="btn-view"><i class="fa-solid fa-rotate"></i> Sync Logs</a>
            <a href="{{ route('admin.aliexpress.api-logs') }}" class="btn-view"><i class="fa-solid fa-triangle-exclamation"></i> API Logs</a>
        </div>
    </div>
    <form method="GET" action="{{ route('admin.aliexpress.index') }}" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem;">
        <div class="search-box">
            <i class="fa-solid fa-search"></i>
            <input type="text" name="search" placeholder="Search seller, account, member ID..." value="{{ request('search') }}" onchange="this.form.submit()" />
        </div>
        <select name="status" onchange="this.form.submit()" style="padding:0.4rem 1.8rem 0.4rem 0.6rem; border:1px solid #ddd; border-radius:0.375rem; font-size:0.875rem; background:#fff;">
            <option value="">All Statuses</option>
            @foreach($statuses as $s)
                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <select name="region" onchange="this.form.submit()" style="padding:0.4rem 1.8rem 0.4rem 0.6rem; border:1px solid #ddd; border-radius:0.375rem; font-size:0.875rem; background:#fff;">
            <option value="">All Regions</option>
            @foreach($regions as $key => $label)
                <option value="{{ $key }}" {{ request('region') === $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        @if(request()->anyFilled(['search', 'status', 'region']))
            <a href="{{ route('admin.aliexpress.index') }}" class="btn-cancel" style="text-decoration:none; padding:0.4rem 0.75rem; font-size:0.875rem;"><i class="fa-solid fa-rotate-left"></i></a>
        @endif
    </form>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th>Seller</th>
                <th>AliExpress Account</th>
                <th>Region</th>
                <th>Status</th>
                <th>Token</th>
                <th>Last Sync</th>
                <th>Imports</th>
                <th>Products</th>
                <th style="width:180px; text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($accounts as $account)
            <tr>
                <td>#{{ $account->id }}</td>
                <td>
                    <div style="font-size:0.8125rem; font-weight:600;">{{ $account->profile?->full_name ?? $account->user?->name ?? 'N/A' }}</div>
                    <div style="font-size:0.75rem; color:#888;">{{ $account->profile?->username ?? '' }} {{ $account->user?->email ? '· ' . $account->user->email : '' }}</div>
                </td>
                <td>
                    <div style="font-size:0.8125rem;">{{ $account->account_name ?? '—' }}</div>
                    <div style="font-size:0.7rem; color:#999;">Member: {{ $account->aliexpress_member_id ?? '—' }}</div>
                </td>
                <td style="font-size:0.8125rem; text-transform:uppercase;">{{ $account->region }}</td>
                <td>
                    @if($account->status === 'active')
                        <span class="badge" style="background:#f0fdf4; color:#166534; text-transform:capitalize;">Active</span>
                    @else
                        <span class="badge" style="background:#fef2f2; color:#b91c1c; text-transform:capitalize;">Disabled</span>
                    @endif
                </td>
                <td>
                    @if($account->status !== 'active')
                        <span style="font-size:0.75rem; color:#999;">—</span>
                    @elseif($account->needsReconnect())
                        <span class="badge" style="background:#fef2f2; color:#dc2626;">Needs reconnect</span>
                    @elseif($account->isTokenExpired())
                        <span class="badge" style="background:#fefce8; color:#a16207;">Expired — auto</span>
                    @else
                        <span class="badge" style="background:#f0fdf4; color:#16a34a;">Valid</span>
                    @endif
                </td>
                <td style="font-size:0.75rem; color:#888;">{{ $account->last_sync_at?->format('M d, Y H:i') ?? '—' }}</td>
                <td style="font-size:0.8125rem;">{{ $account->imports_count }}</td>
                <td style="font-size:0.8125rem;">{{ $account->products_count }}</td>
                <td style="text-align:right;">
                    <div style="display:flex; gap:0.3rem; justify-content:flex-end; align-items:center;">
                        <a href="{{ route('admin.aliexpress.show', $account->id) }}" class="btn-view-icon" title="View"><i class="fa-solid fa-eye"></i></a>
                        @if($account->status === 'active')
                            <form method="POST" action="{{ route('admin.aliexpress.refresh-token', $account->id) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn-edit btn-sm" title="Refresh Token"><i class="fa-solid fa-arrows-rotate"></i></button>
                            </form>
                            <form method="POST" action="{{ route('admin.aliexpress.disable', $account->id) }}" class="swal-confirm" data-swal-title="Disable connection?" data-swal-text="The seller will no longer be able to use this AliExpress connection. Imported products are preserved." data-swal-confirm-text="Yes, disable" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn-delete btn-sm" title="Disable"><i class="fa-solid fa-ban"></i></button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.aliexpress.reactivate', $account->id) }}" class="swal-confirm" data-swal-title="Re-enable connection?" data-swal-text="This will reactivate the seller's AliExpress connection." data-swal-confirm-text="Yes, re-enable" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn-approve btn-sm" title="Re-enable"><i class="fa-solid fa-rotate-right"></i></button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($accounts->isEmpty())
        <p style="text-align:center; color:#999; padding:2rem; font-size:0.875rem;">No AliExpress connections found.</p>
    @endif
</div>

@if($accounts->hasPages())
    <div style="margin-top:1rem;">{{ $accounts->links() }}</div>
@endif
@endsection
