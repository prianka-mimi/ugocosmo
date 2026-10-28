@extends('layouts.dashboard')

@section('page-title', 'Moderate Bookings')

@section('content')
<div style="display: flex; gap: 0.5rem; border-bottom: 2px solid #e5e7eb; margin-bottom: 1.5rem; padding-bottom: 2px; overflow-x: auto; font-family: 'Inter', sans-serif;">
    <a href="{{ route('admin.moderation.posts') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.posts*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.posts*') ? '#0d9488' : '#6b7280' }};">Posts</a>
    <a href="{{ route('admin.moderation.products') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.products*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.products*') ? '#0d9488' : '#6b7280' }};">Products</a>
    <a href="{{ route('admin.moderation.services') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.services*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.services*') ? '#0d9488' : '#6b7280' }};">Services</a>
    <a href="{{ route('admin.moderation.jobs') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.jobs*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.jobs*') ? '#0d9488' : '#6b7280' }};">Jobs</a>
    <a href="{{ route('admin.moderation.events') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.events*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.events*') ? '#0d9488' : '#6b7280' }};">Events</a>
    <a href="{{ route('admin.moderation.bookings') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.bookings*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.bookings*') ? '#0d9488' : '#6b7280' }};">Bookings</a>
    <a href="{{ route('admin.moderation.job-applications') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.job-applications*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.job-applications*') ? '#0d9488' : '#6b7280' }};">Job Applications</a>
    <a href="{{ route('admin.moderation.tickets') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.tickets*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.tickets*') ? '#0d9488' : '#6b7280' }};">Tickets</a>
</div>

<div class="card">
    <div class="card-header">
        <h3>All Bookings</h3>
    </div>
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1.25rem; font-size: 0.8125rem;">{{ session('error') }}</div>
    @endif
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width: 50px;">ID</th>
                <th>Service</th>
                <th>Provider</th>
                <th>Customer</th>
                <th>Date / Time</th>
                <th>Total</th>
                <th>Status</th>
                <th style="width: 160px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bookings as $booking)
            <tr>
                <td>{{ $booking->id }}</td>
                <td>
                    <div style="font-weight: 500; font-size: 0.8125rem;">{{ $booking->service?->title ?? 'N/A' }}</div>
                </td>
                <td style="font-size: 0.8125rem;">{{ $booking->provider?->full_name ?? 'Unknown' }}</td>
                <td style="font-size: 0.8125rem;">{{ $booking->customer?->full_name ?? 'Unknown' }}</td>
                <td style="font-size: 0.8125rem;">
                    {{ $booking->booking_date ? $booking->booking_date->format('Y-m-d') : 'N/A' }} 
                    @if($booking->booking_time)
                        ({{ $booking->booking_time }})
                    @endif
                </td>
                <td>£{{ number_format($booking->total ?? 0, 2) }}</td>
                <td>
                    @if($booking->status === 'confirmed')
                        <span class="badge" style="background: #f0fdf4; color: #166534;">Confirmed</span>
                    @elseif($booking->status === 'cancelled')
                        <span class="badge" style="background: #fef2f2; color: #b91c1c;">Cancelled</span>
                    @else
                        <span class="badge" style="background: #fef3c7; color: #d97706;">{{ ucfirst($booking->status) }}</span>
                    @endif
                </td>
                <td style="text-align: right; white-space: nowrap;">
                    <a href="{{ route('admin.moderation.bookings.show', $booking) }}" class="btn-edit" style="text-decoration: none; display: inline-block; margin-right: 0.25rem;"><i class="fa-solid fa-eye"></i> Details</a>
                    @if($booking->status !== 'cancelled')
                    <form method="POST" action="{{ route('admin.moderation.bookings.deactivate', $booking) }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn-reject" onclick="return confirm('Cancel this booking?')"><i class="fa-solid fa-ban"></i> Cancel</button>
                    </form>
                    @else
                    <form method="POST" action="{{ route('admin.moderation.bookings.activate', $booking) }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn-approve"><i class="fa-solid fa-check"></i> Confirm</button>
                    </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($bookings->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No bookings found.</p>
    @endif
    <div style="margin-top: 1rem;">
        {{ $bookings->links() }}
    </div>
</div>
@endsection
