@extends('layouts.dashboard')

@section('page-title', 'Admin Dashboard')

@section('content')
{{-- Stats Cards --}}
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">{{ $stats['users']['total'] }}</div>
        <div style="font-size: 0.75rem; color: #888; margin-top: 0.25rem;">Total Users</div>
        <div style="display: flex; justify-content: center; gap: 0.75rem; margin-top: 0.5rem; font-size: 0.6875rem;">
            <span style="color: #22c55e;">{{ $stats['users']['active'] }} active</span>
            <span style="color: #ef4444;">{{ $stats['users']['suspended'] }} suspended</span>
        </div>
    </div>
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">{{ $stats['users']['new_today'] }}</div>
        <div style="font-size: 0.75rem; color: #888; margin-top: 0.25rem;">New Today</div>
        <div style="font-size: 0.6875rem; color: #999; margin-top: 0.5rem;">{{ $stats['users']['unverified'] }} unverified</div>
    </div>
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">{{ $stats['content']['posts'] }}</div>
        <div style="font-size: 0.75rem; color: #888; margin-top: 0.25rem;">Total Posts</div>
        <div style="font-size: 0.6875rem; color: #999; margin-top: 0.5rem;">
            {{ $stats['content']['products'] }} products &bull; {{ $stats['content']['services'] }} services
        </div>
    </div>
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">{{ $stats['content']['bookings'] }}</div>
        <div style="font-size: 0.75rem; color: #888; margin-top: 0.25rem;">Bookings</div>
        <div style="font-size: 0.6875rem; color: #999; margin-top: 0.5rem;">{{ $stats['content']['jobs'] }} jobs &bull; {{ $stats['content']['events'] }} events</div>
    </div>
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">£{{ number_format($stats['revenue']['total'], 0) }}</div>
        <div style="font-size: 0.75rem; color: #888; margin-top: 0.25rem;">Total Revenue</div>
        <div style="font-size: 0.6875rem; color: #999; margin-top: 0.5rem;">
            £{{ number_format($stats['revenue']['today'], 0) }} today &bull; £{{ number_format($stats['revenue']['month'], 0) }} this month
        </div>
    </div>
    <div class="card" style="text-align: center; border-left: 4px solid #16a34a;">
        <div style="font-size: 2rem; font-weight: 700; color: #16a34a;">${{ number_format($stats['commission']['total'], 2) }}</div>
        <div style="font-size: 0.75rem; font-weight: 600; color: #333; margin-top: 0.25rem;">Admin Commission (2.5%)</div>
        <div style="font-size: 0.6875rem; color: #999; margin-top: 0.5rem;">
            ${{ number_format($stats['commission']['today'], 2) }} today &bull; ${{ number_format($stats['commission']['month'], 2) }} this month
        </div>
    </div>
    <div class="card" style="text-align: center;">
        <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">{{ $stats['subscriptions']['active'] }}</div>
        <div style="font-size: 0.75rem; color: #888; margin-top: 0.25rem;">Active Subscriptions</div>
        <div style="font-size: 0.6875rem; color: #999; margin-top: 0.5rem;">{{ $stats['sellers'] }} sellers</div>
    </div>
</div>

{{-- Revenue Chart --}}
<div class="card" style="margin-bottom: 2rem;">
    <div class="card-header">
        <h3>Revenue (Last 6 Months)</h3>
    </div>
    @if($revenueByMonth->isNotEmpty())
    <div style="display: flex; align-items: flex-end; gap: 0.75rem; height: 200px; padding: 1rem 0;">
        @php $maxRevenue = $revenueByMonth->max('total') ?: 1; @endphp
        @foreach($revenueByMonth->reverse() as $item)
            @php
                $height = max(($item->total / $maxRevenue) * 100, 4);
                $monthName = date('M', mktime(0, 0, 0, $item->month, 1));
            @endphp
            <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.35rem;">
                <span style="font-size: 0.6875rem; color: #888;">£{{ number_format($item->total, 0) }}</span>
                <div style="width: 100%; height: {{ $height }}%; background: var(--primary); border-radius: 0.375rem 0.375rem 0 0; min-height: 8px; transition: height 0.3s ease;"></div>
                <span style="font-size: 0.6875rem; color: #888;">{{ $monthName }}</span>
            </div>
        @endforeach
    </div>
    @else
    <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No revenue data yet.</p>
    @endif
</div>

{{-- Quick Links --}}
<div class="card">
    <div class="card-header">
        <h3>Quick Actions</h3>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem;">
        @canPermission('users.view')
        <a href="{{ route('admin.users') }}" class="btn-primary" style="text-align: center; padding: 1rem; border-radius: 0.75rem; text-decoration: none;">
            <i class="fa-solid fa-users" style="font-size: 1.25rem; display: block; margin-bottom: 0.35rem;"></i>
            Manage Users
        </a>
        @endcanPermission
        <a href="{{ route('admin.wallet.index') }}" class="btn-primary" style="text-align: center; padding: 1rem; border-radius: 0.75rem; text-decoration: none; background: #16a34a;">
            <i class="fa-solid fa-wallet" style="font-size: 1.25rem; display: block; margin-bottom: 0.35rem;"></i>
            Platform Wallet (2.5%)
        </a>
        @canPermission('posts.moderate')
        <a href="{{ route('admin.moderation.posts') }}" class="btn-primary" style="text-align: center; padding: 1rem; border-radius: 0.75rem; text-decoration: none; background: #0d9488;">
            <i class="fa-solid fa-flag" style="font-size: 1.25rem; display: block; margin-bottom: 0.35rem;"></i>
            Moderate Posts
        </a>
        @endcanPermission
        @canPermission('permissions.manage')
        <a href="{{ route('admin.roles.index') }}" class="btn-primary" style="text-align: center; padding: 1rem; border-radius: 0.75rem; text-decoration: none; background: #7c3aed;">
            <i class="fa-solid fa-shield-halved" style="font-size: 1.25rem; display: block; margin-bottom: 0.35rem;"></i>
            Roles & Permissions
        </a>
        @endcanPermission
        @canPermission('audit.view')
        <a href="{{ route('admin.audit') }}" class="btn-primary" style="text-align: center; padding: 1rem; border-radius: 0.75rem; text-decoration: none; background: #0891b2;">
            <i class="fa-solid fa-clock-rotate-left" style="font-size: 1.25rem; display: block; margin-bottom: 0.35rem;"></i>
            Audit Logs
        </a>
        @endcanPermission
    </div>
</div>
@endsection
