@extends('layouts.dashboard')

@section('page-title', 'Subscription Plan Details')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Plan Details</h3>
        <div class="card-header-actions">
            <a href="{{ route('subscription.index') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.35rem;"></i> Back</a>
            <a href="{{ route('subscription.edit', $plan) }}" class="btn-edit"><i class="fa-solid fa-pen"></i> Edit</a>
        </div>
    </div>
    <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Plan Name</label>
            <p style="font-size: 1.25rem; font-weight: 700; color: #1b1b18; margin: 0;">{{ $plan->name }}</p>
            @if($plan->is_popular)
                <span class="badge badge-popular" style="margin-top: 0.5rem; display: inline-block;">Most Popular</span>
            @endif
        </div>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Price</label>
            <p style="font-size: 1.5rem; font-weight: 700; color: #E87450; margin: 0;">
                @if($plan->price > 0)
                    £{{ number_format($plan->price, 2) }} <span style="font-size: 0.875rem; color: #888; font-weight: 400;">/month</span>
                @else
                    Free
                @endif
            </p>
        </div>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Description</label>
            <p style="font-size: 0.9375rem; color: #333; margin: 0;">{{ $plan->description ?? '-' }}</p>
        </div>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.5rem;">Features</label>
            <ul class="plan-features">
                @foreach($plan->features ?? [] as $feature)
                <li><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                @endforeach
            </ul>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Status</label>
            <x-status-badge :status="$plan->status" />
        </div>
    </div>
</div>
@endsection
