@extends('emails.layout')

@section('title', 'Order Confirmed!')
@section('subtitle', "Order #{$orderRef}")

@section('content')
    <h2>Thank you for your order, {{ $customerName }}!</h2>
    <p>We've received your order and are preparing it for shipment. Below are your order details and summary:</p>

    <div class="details">
        <table>
            <tr>
                <td>Order Number:</td>
                <td><strong>#{{ $orderRef }}</strong></td>
            </tr>
            <tr>
                <td>Order Date:</td>
                <td>{{ $order->created_at ? $order->created_at->format('M d, Y h:i A') : date('M d, Y') }}</td>
            </tr>
            <tr>
                <td>Status:</td>
                <td><span style="color: #10b981; font-weight: 600;">Confirmed</span></td>
            </tr>
            @if(!empty($shippingAddress['line1']))
                <tr>
                    <td>Delivery Address:</td>
                    <td>
                        {{ $shippingAddress['name'] ?? $customerName }}<br>
                        {{ $shippingAddress['line1'] }}<br>
                        @if(!empty($shippingAddress['line2']))
                            {{ $shippingAddress['line2'] }}<br>
                        @endif
                        {{ $shippingAddress['city'] ?? '' }}, {{ $shippingAddress['state'] ?? '' }} {{ $shippingAddress['postal_code'] ?? '' }}<br>
                        {{ $shippingAddress['country'] ?? 'US' }}
                    </td>
                </tr>
            @endif
        </table>
    </div>

    <h3>Items Ordered</h3>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <thead>
            <tr style="border-bottom: 2px solid #e8e8f0; text-align: left;">
                <th style="padding: 8px 0; color: #4a4a6a; font-size: 14px;">Item</th>
                <th style="padding: 8px; text-align: center; color: #4a4a6a; font-size: 14px;">Qty</th>
                <th style="padding: 8px 0; text-align: right; color: #4a4a6a; font-size: 14px;">Price</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr style="border-bottom: 1px solid #f0f0f5;">
                    <td style="padding: 10px 0; font-size: 14px; color: #1a1a2e;">
                        {{ $item->title }}
                    </td>
                    <td style="padding: 10px; text-align: center; font-size: 14px; color: #4a4a6a;">
                        {{ $item->quantity }}
                    </td>
                    <td style="padding: 10px 0; text-align: right; font-size: 14px; color: #1a1a2e; font-weight: 500;">
                        ${{ number_format($item->total_price, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" style="padding: 12px 0 4px; text-align: right; color: #8888aa; font-size: 14px;">Subtotal:</td>
                <td style="padding: 12px 0 4px; text-align: right; font-size: 14px; color: #1a1a2e;">${{ number_format($subtotal, 2) }}</td>
            </tr>
            <tr>
                <td colspan="2" style="padding: 4px 0; text-align: right; color: #8888aa; font-size: 14px;">Shipping:</td>
                <td style="padding: 4px 0; text-align: right; font-size: 14px; color: #1a1a2e;">${{ number_format($shippingAmount, 2) }}</td>
            </tr>
            <tr style="border-top: 2px solid #1a1a2e;">
                <td colspan="2" style="padding: 8px 0; text-align: right; font-size: 16px; font-weight: 700; color: #1a1a2e;">Total:</td>
                <td style="padding: 8px 0; text-align: right; font-size: 16px; font-weight: 700; color: #6366f1;">${{ number_format($total, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div style="text-align: center; margin-top: 28px;">
        <p style="color: #4a4a6a; margin-bottom: 16px;">Discover more great products, trending collections, and related gear:</p>
        <a href="{{ $shopUrl }}" class="cta-button">Continue Shopping &amp; Explore Trending Products</a>
    </div>
@endsection

