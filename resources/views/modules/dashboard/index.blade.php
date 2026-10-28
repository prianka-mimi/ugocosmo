@extends('layouts.dashboard')

@section('page-title', 'Dashboard')

@section('content')
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: #E87450;">{{ $plans->count() }}</div>
        <div style="font-size: 0.8125rem; color: #888; margin-top: 0.25rem;">Subscription Plans</div>
    </div>
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: #E87450;">{{ \App\Models\User::count() }}</div>
        <div style="font-size: 0.8125rem; color: #888; margin-top: 0.25rem;">Total Users</div>
    </div>
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: #E87450;">{{ \App\Models\Faq::count() }}</div>
        <div style="font-size: 0.8125rem; color: #888; margin-top: 0.25rem;">FAQ Entries</div>
    </div>
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: #E87450;">{{ \App\Models\Page::count() }}</div>
        <div style="font-size: 0.8125rem; color: #888; margin-top: 0.25rem;">CMS Pages</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Subscription Plans Overview</h3>
        <a href="{{ route('subscription.index') }}" class="btn-primary">Manage Plans</a>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem;">
        @foreach($plans as $plan)
        <div class="plan-card {{ $plan->is_popular ? 'popular' : '' }}">
            @if($plan->is_popular)
            <div class="popular-badge">Most Popular</div>
            @endif
            <div class="plan-name">{{ $plan->name }}</div>
            <div class="plan-price">
                @if($plan->price > 0)
                    £{{ number_format($plan->price, 2) }}
                    <span>/month</span>
                @else
                    Free
                @endif
            </div>
            <div class="plan-desc">{{ $plan->description ?? '' }}</div>
            <ul class="plan-features">
                @foreach($plan->features ?? [] as $feature)
                <li><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                @endforeach
            </ul>
        </div>
        @endforeach
    </div>
</div>
@endsection