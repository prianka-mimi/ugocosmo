@extends('layouts.dashboard')

@section('page-title', 'Platform Wallet & Commission (2.5%)')

@section('content')
{{-- 1. KPI Stats Cards --}}
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    <div class="card" style="text-align: center; border-left: 4px solid #16a34a;">
        <div style="font-size: 2rem; font-weight: 700; color: #16a34a;">${{ number_format($stats['total_commission'], 2) }}</div>
        <div style="font-size: 0.8125rem; font-weight: 600; color: #333; margin-top: 0.25rem;">Total Admin Commission</div>
        <div style="font-size: 0.6875rem; color: #888; margin-top: 0.25rem;">2.5% fee deducted from sellers (0% buyer fee)</div>
    </div>

    <div class="card" style="text-align: center; border-left: 4px solid var(--primary);">
        <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">${{ number_format($stats['month_commission'], 2) }}</div>
        <div style="font-size: 0.8125rem; font-weight: 600; color: #333; margin-top: 0.25rem;">This Month's Commission</div>
        <div style="font-size: 0.6875rem; color: #888; margin-top: 0.25rem;">${{ number_format($stats['today_commission'], 2) }} earned today</div>
    </div>

    <div class="card" style="text-align: center; border-left: 4px solid #0891b2;">
        <div style="font-size: 2rem; font-weight: 700; color: #0891b2;">${{ number_format($stats['total_volume'], 2) }}</div>
        <div style="font-size: 0.8125rem; font-weight: 600; color: #333; margin-top: 0.25rem;">Total Marketplace Sales</div>
        <div style="font-size: 0.6875rem; color: #888; margin-top: 0.25rem;">Across {{ number_format($stats['total_orders_count']) }} settled orders</div>
    </div>

    <div class="card" style="text-align: center; border-left: 4px solid #8b5cf6;">
        <div style="font-size: 2rem; font-weight: 700; color: #8b5cf6;">${{ number_format($stats['seller_payouts'], 2) }}</div>
        <div style="font-size: 0.8125rem; font-weight: 600; color: #333; margin-top: 0.25rem;">Seller Payouts (97.5%)</div>
        <div style="font-size: 0.6875rem; color: #888; margin-top: 0.25rem;">Net earnings credited to sellers</div>
    </div>

    @if($stats['pending_commission'] > 0)
    <div class="card" style="text-align: center; border-left: 4px solid #f59e0b;">
        <div style="font-size: 2rem; font-weight: 700; color: #f59e0b;">${{ number_format($stats['pending_commission'], 2) }}</div>
        <div style="font-size: 0.8125rem; font-weight: 600; color: #333; margin-top: 0.25rem;">Pending Commission</div>
        <div style="font-size: 0.6875rem; color: #888; margin-top: 0.25rem;">From uncompleted orders</div>
    </div>
    @endif
</div>

{{-- 2. Commission Breakdown Table --}}
<div class="card" style="margin-bottom: 2rem;">
    <div class="card-header">
        <div>
            <h3 style="margin-bottom: 0.25rem;">Platform Commission Ledger</h3>
            <p style="font-size: 0.75rem; color: #64748b; margin: 0;">
                Order-by-order breakdown: <strong>2.5% processing fee is deducted from the seller's payout</strong> and retained by the platform. <strong>Buyers are charged 0% platform fee.</strong>
            </p>
        </div>
        <form method="GET" action="{{ route('admin.wallet.index') }}" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <div class="search-box">
                <i class="fa-solid fa-search"></i>
                <input type="text" name="search" placeholder="Search order, seller, buyer..." value="{{ request('search') }}" onchange="this.form.submit()" />
            </div>

            <select name="status" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.8125rem; background: #fff;">
                <option value="">All Statuses</option>
                @foreach($statuses as $st)
                    @if($st !== 'all')
                    <option value="{{ $st }}" {{ request('status') === $st ? 'selected' : '' }}>{{ ucfirst($st) }}</option>
                    @endif
                @endforeach
            </select>

            <input type="date" name="start_date" value="{{ request('start_date') }}" onchange="this.form.submit()" style="padding: 0.35rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.8125rem; background: #fff;" title="From Date" />
            <span style="font-size: 0.75rem; color: #888;">to</span>
            <input type="date" name="end_date" value="{{ request('end_date') }}" onchange="this.form.submit()" style="padding: 0.35rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.8125rem; background: #fff;" title="To Date" />

            @if(request()->anyFilled(['search', 'status', 'start_date', 'end_date']))
                <a href="{{ route('admin.wallet.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.4rem 0.75rem; font-size: 0.8125rem;" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            @endif
        </form>
    </div>

    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th style="width: 75px;">Order #</th>
                    <th>Date</th>
                    <th>Seller</th>
                    <th>Buyer</th>
                    <th style="text-align: right;">Subtotal</th>
                    <th style="text-align: right; background: #fff1f2; color: #be123c;">Seller Fee (2.5% Deducted)</th>
                    <th style="text-align: right; font-weight: 600;">Seller Payout (97.5%)</th>
                    <th style="text-align: right; background: #f0fdf4; color: #166534;">Admin Kept (2.5%)</th>
                    <th style="text-align: right;">Buyer Paid</th>
                    <th style="text-align: center;">Status</th>
                    <th style="width: 60px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                @php
                    $subtotal = (float) $order->subtotal;
                    $adminCommission = $order->seller_fee !== null ? (float) $order->seller_fee : round($subtotal * 0.025, 2);
                    $sellerNet = max(0, round($subtotal - $adminCommission, 2));

                    $statusColors = [
                        'pending' => '#f59e0b', 'confirmed' => '#0891b2', 'processing' => '#6366f1',
                        'shipped' => '#8b5cf6', 'delivered' => '#22c55e', 'completed' => '#16a34a',
                        'cancelled' => '#ef4444', 'refunded' => '#f97316', 'disputed' => '#dc2626',
                    ];
                    $sc = $statusColors[$order->status] ?? '#555';
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('admin.orders.show', $order) }}" style="font-weight: 600; color: var(--primary); text-decoration: none;">
                            #{{ $order->id }}
                        </a>
                    </td>
                    <td style="font-size: 0.75rem; color: #777;">
                        {{ $order->created_at->format('M d, Y') }}<br>
                        <span style="font-size: 0.6875rem; color: #aaa;">{{ $order->created_at->format('h:i A') }}</span>
                    </td>
                    <td>
                        <div style="font-weight: 500; font-size: 0.8125rem;">{{ $order->seller?->full_name ?? ($order->seller?->username ?? 'N/A') }}</div>
                        <div style="font-size: 0.6875rem; color: #888;">ID: {{ $order->seller_id }}</div>
                    </td>
                    <td>
                        <div style="font-size: 0.8125rem;">{{ $order->buyer?->full_name ?? ($order->buyer?->username ?? 'Guest') }}</div>
                    </td>
                    <td style="text-align: right; font-weight: 500;">
                        ${{ number_format($subtotal, 2) }}
                    </td>
                    <td style="text-align: right; font-weight: 600; background: #fff1f2; color: #be123c;">
                        -${{ number_format($adminCommission, 2) }}
                    </td>
                    <td style="text-align: right; font-weight: 700; color: #0f172a;">
                        ${{ number_format($sellerNet, 2) }}
                    </td>
                    <td style="text-align: right; font-weight: 600; background: #f0fdf4; color: #166534;">
                        ${{ number_format($adminCommission, 2) }}
                    </td>
                    <td style="text-align: right; font-weight: 600;">
                        ${{ number_format($order->total, 2) }}
                    </td>
                    <td style="text-align: center;">
                        <span class="badge" style="background: {{ $sc }}15; color: {{ $sc }}; text-transform: capitalize; font-size: 0.6875rem;">
                            {{ $order->status }}
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <a href="{{ route('admin.orders.show', $order) }}" class="btn-view-icon" title="View Order Details">
                            <i class="fa-solid fa-eye"></i>
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="10" style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">
                        No commission transactions found for the selected criteria.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($orders->hasPages())
    <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end;">
        {{ $orders->links() }}
    </div>
    @endif
</div>

{{-- 3. Recent Seller Payouts / Credits Log --}}
@if($recentCredits->isNotEmpty())
<div class="card">
    <div class="card-header">
        <div>
            <h3>Recent Seller Net Credits (97.5%)</h3>
            <p style="font-size: 0.75rem; color: #888; margin: 0;">Audit log of recent automated wallet credits disbursed to sellers after deducting 2.5% platform fee.</p>
        </div>
    </div>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Ref #</th>
                    <th>Date</th>
                    <th>Seller Profile</th>
                    <th>Description</th>
                    <th style="text-align: right;">Amount Credited</th>
                    <th style="text-align: right;">Balance After</th>
                    <th style="text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentCredits as $credit)
                <tr>
                    <td><code>{{ $credit->reference ?? 'TX-' . $credit->id }}</code></td>
                    <td style="font-size: 0.75rem; color: #888;">{{ $credit->created_at->format('M d, Y h:i A') }}</td>
                    <td style="font-size: 0.8125rem;">
                        <strong>{{ $credit->user?->full_name ?? ($credit->user?->username ?? 'Profile #' . $credit->user_id) }}</strong>
                    </td>
                    <td style="font-size: 0.8125rem; color: #555;">{{ $credit->description }}</td>
                    <td style="text-align: right; font-weight: 600; color: #16a34a;">+${{ number_format($credit->amount, 2) }}</td>
                    <td style="text-align: right; font-size: 0.8125rem;">${{ number_format($credit->balance_after ?? 0, 2) }}</td>
                    <td style="text-align: center;">
                        <span class="badge" style="background: #22c55e15; color: #22c55e; text-transform: capitalize;">{{ $credit->status }}</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@endsection
