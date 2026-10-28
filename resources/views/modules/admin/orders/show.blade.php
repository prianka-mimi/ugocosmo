@extends('layouts.dashboard')

@section('page-title', 'Order #' . $order->id)

@section('content')
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
    <div class="card">
        <div class="card-header"><h3>Order Information</h3></div>
        <table>
            <tr><td style="font-weight: 500; width: 140px;">Order ID</td><td>#{{ $order->id }}</td></tr>
            <tr><td style="font-weight: 500;">Status</td>
                <td>
                    @php
                        $statusColors = ['pending'=>'#f59e0b','confirmed'=>'#0891b2','processing'=>'#6366f1','shipped'=>'#8b5cf6','delivered'=>'#22c55e','completed'=>'#16a34a','cancelled'=>'#ef4444','refunded'=>'#f97316','disputed'=>'#dc2626'];
                        $sc = $statusColors[$order->status] ?? '#555';
                    @endphp
                    <span class="badge" style="background: {{ $sc }}15; color: {{ $sc }}; text-transform: capitalize;">{{ $order->status }}</span>
                </td>
            </tr>
            <tr><td style="font-weight: 500;">Total Amount</td><td style="font-weight: 600; font-size: 1rem;">${{ number_format($order->total, 2) }}</td></tr>
            <tr><td style="font-weight: 500;">Subtotal</td><td>${{ number_format($order->subtotal, 2) }}</td></tr>
            <tr><td style="font-weight: 500;">Platform Fee</td><td>${{ number_format($order->platform_fee_total, 2) }}</td></tr>
            <tr><td style="font-weight: 500;">Tax</td><td>${{ number_format($order->tax_amount, 2) }}</td></tr>
            <tr><td style="font-weight: 500;">Created</td><td>{{ $order->created_at->format('M d, Y h:i A') }}</td></tr>
            <tr><td style="font-weight: 500;">Tracking #</td><td>{{ $order->tracking_number ?? 'N/A' }}</td></tr>
        </table>
    </div>

    <div class="card">
        <div class="card-header"><h3>Parties</h3></div>
        <table>
            <tr>
                <td style="font-weight: 500; width: 100px; vertical-align: top;">Buyer</td>
                <td>
                    <div style="font-weight: 500;">{{ $order->buyer?->full_name ?? 'N/A' }}</div>
                    <div style="font-size: 0.75rem; color: #888;">Profile ID: {{ $order->buyer_id }}</div>
                </td>
            </tr>
            <tr>
                <td style="font-weight: 500; vertical-align: top;">Seller</td>
                <td>
                    <div style="font-weight: 500;">{{ $order->seller?->full_name ?? 'N/A' }}</div>
                    <div style="font-size: 0.75rem; color: #888;">Profile ID: {{ $order->seller_id }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="card-header"><h3>Payment Details</h3></div>
        @if($order->stripe_payment_intent_id)
        <table>
            <tr>
                <td style="font-weight: 500; width: 120px;">Stripe Reference</td>
                <td><code style="font-size: 0.75rem; word-break: break-all;">{{ $order->stripe_payment_intent_id }}</code></td>
            </tr>
            @if($paymentDetails)
                <tr>
                    <td style="font-weight: 500;">Stripe Status</td>
                    <td>
                        <span style="text-transform: capitalize;">{{ str_replace('_', ' ', $paymentDetails['payment_status']) }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="font-weight: 500;">Amount</td>
                    <td style="font-weight: 600;">${{ number_format($paymentDetails['amount'] ?? $order->total, 2) }}</td>
                </tr>
                @if($paymentDetails['paid_at'])
                    <tr>
                        <td style="font-weight: 500;">Paid At</td>
                        <td>{{ \Carbon\Carbon::parse($paymentDetails['paid_at'])->format('M d, Y h:i A') }}</td>
                    </tr>
                @endif
                @if($paymentDetails['receipt_url'])
                    <tr>
                        <td colspan="2">
                            <a href="{{ $paymentDetails['receipt_url'] }}" target="_blank" class="btn-view-icon" style="text-decoration: none; padding: 0.35rem 0.75rem; font-size: 0.8125rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                                <i class="fa-solid fa-receipt"></i> View Stripe Receipt
                            </a>
                        </td>
                    </tr>
                @endif
                @if($paymentDetails['checkout_url'] && $order->status === 'pending')
                    <tr>
                        <td colspan="2">
                            <a href="{{ $paymentDetails['checkout_url'] }}" target="_blank" class="btn-view-icon" style="text-decoration: none; padding: 0.35rem 0.75rem; font-size: 0.8125rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                                <i class="fa-solid fa-link"></i> Pay / Open Checkout Link
                            </a>
                        </td>
                    </tr>
                @endif
            @else
                <tr>
                    <td style="font-weight: 500;">Payment Status</td>
                    <td><span class="badge" style="background: #fff7ed; color: #c2410c;">Unpaid</span></td>
                </tr>
            @endif
        </table>
        @else
            <p style="font-size: 0.875rem; color: #888; margin: 1rem 0 0 0;">No Stripe payment record yet.</p>
        @endif
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header"><h3>Order Items</h3></div>
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Price</th>
                <th>Quantity</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse($order->items as $item)
            <tr>
                <td>{{ $item->title ?? 'Item #'.$item->id }}</td>
                <td>${{ number_format($item->unit_price ?? 0, 2) }}</td>
                <td>{{ $item->quantity ?? 1 }}</td>
                <td>${{ number_format(($item->unit_price ?? 0) * ($item->quantity ?? 1), 2) }}</td>
            </tr>
            @empty
            <tr><td colspan="4" style="text-align: center; color: #999;">No items</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($order->disputes && $order->disputes->count() > 0)
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header"><h3>Related Disputes</h3></div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Raised By</th>
                <th>Date</th>
                <th style="text-align: right;">Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->disputes as $dispute)
            <tr>
                <td>#{{ $dispute->id }}</td>
                <td style="font-size: 0.8125rem;">{{ $dispute->reason }}</td>
                <td><span class="badge" style="background: {{ $dispute->status === 'resolved' ? '#22c55e' : '#f59e0b' }}15; color: {{ $dispute->status === 'resolved' ? '#22c55e' : '#f59e0b' }};">{{ ucfirst($dispute->status) }}</span></td>
                <td style="font-size: 0.8125rem;">{{ $dispute->raisedBy?->full_name ?? 'N/A' }}</td>
                <td style="font-size: 0.75rem; color: #888;">{{ $dispute->created_at->format('M d, Y') }}</td>
                <td style="text-align: right;">
                    <a href="{{ route('admin.disputes.show', $dispute) }}" class="btn-view-icon"><i class="fa-solid fa-eye"></i></a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="card">
    <div class="card-header"><h3>Actions</h3></div>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <form method="POST" action="{{ route('admin.orders.update-status', $order) }}" style="display: flex; gap: 0.5rem; align-items: center;">
            @csrf
            <select name="status" style="padding: 0.45rem 1.8rem 0.45rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff;">
                @foreach(['pending','confirmed','processing','shipped','delivered','completed','cancelled'] as $s)
                    <option value="{{ $s }}" {{ $order->status === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-primary">Update Status</button>
        </form>

        @if(in_array($order->status, ['pending', 'confirmed', 'processing', 'disputed']))
        <form method="POST" action="{{ route('admin.orders.refund', $order) }}" style="display: flex; gap: 0.5rem; align-items: center;" onsubmit="return confirm('Refund this order? This action updates the order status to refunded.')">
            @csrf
            <input type="number" name="amount" step="0.01" value="{{ $order->total }}" style="width: 100px; padding: 0.45rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem;" placeholder="Amount" />
            <button type="submit" class="btn-delete" style="padding: 0.45rem 1rem;"><i class="fa-solid fa-rotate-left"></i> Refund</button>
        </form>
        @endif
    </div>
</div>

<div style="margin-top: 1rem;">
    <a href="{{ route('admin.orders.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem;"><i class="fa-solid fa-arrow-left"></i> Back to Orders</a>
</div>
@endsection
