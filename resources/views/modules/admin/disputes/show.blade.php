@extends('layouts.dashboard')

@section('page-title', 'Dispute #' . $dispute->id)

@section('content')
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <div class="card">
        <div class="card-header"><h3>Dispute Information</h3></div>
        <table>
            <tr><td style="font-weight: 500; width: 140px;">Dispute ID</td><td>#{{ $dispute->id }}</td></tr>
            <tr><td style="font-weight: 500;">Status</td>
                <td>
                    @php
                        $colors = ['pending' => '#f59e0b', 'investigating' => '#6366f1', 'resolved' => '#22c55e', 'dismissed' => '#888'];
                    @endphp
                    <span class="badge" style="background: {{ ($colors[$dispute->status] ?? '#555') }}15; color: {{ $colors[$dispute->status] ?? '#555' }}; text-transform: capitalize;">{{ str_replace('_', ' ', $dispute->status) }}</span>
                </td>
            </tr>
            <tr><td style="font-weight: 500;">Reason</td><td>{{ $dispute->reason }}</td></tr>
            <tr><td style="font-weight: 500;">Description</td><td style="font-size: 0.8125rem;">{{ $dispute->description ?? 'N/A' }}</td></tr>
            <tr><td style="font-weight: 500;">Raised By</td><td>{{ $dispute->raisedBy?->full_name ?? 'N/A' }}</td></tr>
            <tr><td style="font-weight: 500;">Created</td><td>{{ $dispute->created_at->format('M d, Y h:i A') }}</td></tr>
            @if($dispute->resolved_at)
            <tr><td style="font-weight: 500;">Resolved At</td><td>{{ $dispute->resolved_at->format('M d, Y h:i A') }}</td></tr>
            <tr><td style="font-weight: 500;">Resolved By</td><td>{{ $dispute->resolvedBy?->full_name ?? 'N/A' }}</td></tr>
            @endif
            @if($dispute->resolution_notes)
            <tr><td style="font-weight: 500;">Notes</td><td style="font-size: 0.8125rem;">{{ $dispute->resolution_notes }}</td></tr>
            @endif
        </table>
    </div>

    <div class="card">
        <div class="card-header"><h3>Related Order</h3></div>
        <table>
            <tr><td style="font-weight: 500; width: 100px;">Order ID</td><td><a href="{{ route('admin.orders.show', $dispute->order) }}" style="color: var(--primary); text-decoration: none;">#{{ $dispute->order_id }}</a></td></tr>
            <tr><td style="font-weight: 500;">Buyer</td><td>{{ $dispute->order->buyer?->full_name ?? 'N/A' }}</td></tr>
            <tr><td style="font-weight: 500;">Seller</td><td>{{ $dispute->order->seller?->full_name ?? 'N/A' }}</td></tr>
            <tr><td style="font-weight: 500;">Amount</td><td style="font-weight: 600;">${{ number_format($dispute->order->total, 2) }}</td></tr>
            <tr><td style="font-weight: 500;">Order Status</td>
                <td><span class="badge" style="background: #f59e0b15; color: #f59e0b; text-transform: capitalize;">{{ $dispute->order->status }}</span></td>
            </tr>
        </table>
    </div>
</div>

@if($dispute->evidence_urls && count($dispute->evidence_urls) > 0)
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header"><h3>Evidence</h3></div>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        @foreach($dispute->evidence_urls as $url)
            <a href="{{ $url }}" target="_blank" style="padding: 0.5rem 1rem; border: 1px solid #ddd; border-radius: 0.375rem; text-decoration: none; font-size: 0.8125rem; color: var(--primary);">
                <i class="fa-solid fa-link"></i> View Evidence
            </a>
        @endforeach
    </div>
</div>
@endif

@if(!in_array($dispute->status, ['resolved', 'dismissed']))
<div class="card">
    <div class="card-header"><h3>Resolve Dispute</h3></div>
    <form method="POST" action="{{ route('admin.disputes.update-status', $dispute) }}">
        @csrf
        <div style="display: grid; gap: 1rem; max-width: 500px;">
            <div class="form-group">
                <label>Resolution Decision</label>
                <select name="resolution" required style="width: 100%; padding: 0.6rem 0.9rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.9375rem;">
                    <option value="">Select resolution...</option>
                    <option value="refund_buyer">Refund Buyer (order → refunded)</option>
                    <option value="release_seller">Release to Seller (order → completed)</option>
                    <option value="partial_refund">Partial Refund</option>
                </select>
            </div>
            <div class="form-group">
                <label>Resolution Notes</label>
                <textarea name="resolution_notes" rows="3" style="width: 100%; padding: 0.6rem 0.9rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.9375rem;" placeholder="Explain the resolution..."></textarea>
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <button type="submit" name="status" value="resolved" class="btn-primary" style="padding: 0.65rem 1.5rem;">
                    <i class="fa-solid fa-check"></i> Resolve
                </button>
                <button type="submit" name="status" value="dismissed" class="btn-cancel" style="padding: 0.65rem 1.5rem; border: 1px solid #ef4444; color: #ef4444;" onclick="return confirm('Dismiss this dispute?')">
                    <i class="fa-solid fa-xmark"></i> Dismiss
                </button>
            </div>
        </div>
    </form>
</div>
@else
<div class="card">
    <div class="card-header"><h3>Resolution Summary</h3></div>
    <div style="padding: 1rem; background: #f9f9f9; border-radius: 0.5rem;">
        <p style="font-size: 0.875rem; color: #555;">
            <strong>Resolution:</strong> {{ $dispute->resolution ?? 'N/A' }}<br>
            <strong>Notes:</strong> {{ $dispute->resolution_notes ?? 'None' }}
        </p>
    </div>
</div>
@endif

<div style="margin-top: 1rem;">
    <a href="{{ route('admin.disputes.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem;"><i class="fa-solid fa-arrow-left"></i> Back to Disputes</a>
</div>
@endsection
