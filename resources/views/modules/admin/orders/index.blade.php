@extends('layouts.dashboard')

@section('page-title', 'Order Management')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>All Orders</h3>
        <form method="GET" action="{{ route('admin.orders.index') }}" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <div class="search-box">
                <i class="fa-solid fa-search"></i>
                <input type="text" name="search" placeholder="Search buyer, seller, ID..." value="{{ request('search') }}" onchange="this.form.submit()" />
            </div>
            <select name="status" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff;">
                <option value="">All Statuses</option>
                @foreach($statuses as $s)
                    <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            @if(request()->anyFilled(['search', 'status']))
                <a href="{{ route('admin.orders.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.4rem 0.75rem; font-size: 0.875rem;"><i class="fa-solid fa-rotate-left"></i></a>
            @endif
        </form>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width: 60px;">ID</th>
                <th>Buyer</th>
                <th>Seller</th>
                <th>Total</th>
                <th>Status</th>
                <th>Date</th>
                <th style="width: 140px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($orders as $order)
            <tr>
                <td>#{{ $order->id }}</td>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <div style="width: 28px; height: 28px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid fa-user" style="font-size: 0.65rem; color: #bbb;"></i>
                        </div>
                        <span style="font-size: 0.8125rem;">{{ $order->buyer?->full_name ?? 'N/A' }}</span>
                    </div>
                </td>
                <td style="font-size: 0.8125rem;">{{ $order->seller?->full_name ?? 'N/A' }}</td>
                <td style="font-weight: 600;">${{ number_format($order->total, 2) }}</td>
                <td>
                    @php
                        $statusColors = [
                            'pending' => '#f59e0b', 'confirmed' => '#0891b2', 'processing' => '#6366f1',
                            'shipped' => '#8b5cf6', 'delivered' => '#22c55e', 'completed' => '#16a34a',
                            'cancelled' => '#ef4444', 'refunded' => '#f97316', 'disputed' => '#dc2626',
                        ];
                        $color = $statusColors[$order->status] ?? '#555';
                    @endphp
                    <span class="badge" style="background: {{ $color }}15; color: {{ $color }}; text-transform: capitalize;">{{ $order->status }}</span>
                </td>
                <td style="font-size: 0.75rem; color: #888;">{{ $order->created_at->format('M d, Y') }}</td>
                <td style="text-align: right;">
                    <a href="{{ route('admin.orders.show', $order) }}" class="btn-view-icon" title="View Details"><i class="fa-solid fa-eye"></i></a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($orders->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No orders found.</p>
    @endif
</div>
@endsection
