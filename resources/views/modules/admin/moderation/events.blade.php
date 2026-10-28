@extends('layouts.dashboard')

@section('page-title', 'Moderate Events')

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
        <h3>All Events</h3>
        <form method="GET" action="{{ route('admin.moderation.events') }}" style="display: flex; gap: 0.5rem; align-items: center;">
            <select name="is_active" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff;">
                <option value="">All Status</option>
                <option value="1" {{ request('is_active') === '1' ? 'selected' : '' }}>Active</option>
                <option value="0" {{ request('is_active') === '0' ? 'selected' : '' }}>Deactivated</option>
            </select>
            <div class="search-box">
                <i class="fa-solid fa-search"></i>
                <input type="text" name="search" placeholder="Search title, venue..." value="{{ request('search') }}" onchange="this.form.submit()" />
            </div>
        </form>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width: 50px;">ID</th>
                <th>Title</th>
                <th>Organizer</th>
                <th>Date</th>
                <th>Status</th>
                <th style="width: 160px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($events as $event)
            <tr>
                <td>{{ $event->id }}</td>
                <td>
                    <div style="font-weight: 500; font-size: 0.8125rem;">{{ $event->title }}</div>
                </td>
                <td style="font-size: 0.8125rem;">{{ $event->organizer?->full_name ?? 'Unknown' }}</td>
                <td style="font-size: 0.8125rem;">{{ $event->event_date ? $event->event_date->format('d M Y') : '-' }}</td>
                <td>
                    @if($event->is_active)
                        <span class="badge" style="background: #f0fdf4; color: #166534;">Active</span>
                    @else
                        <span class="badge" style="background: #fef2f2; color: #b91c1c;">Deactivated</span>
                    @endif
                </td>
                <td style="text-align: right; white-space: nowrap;">
                    <a href="{{ route('admin.moderation.events.show', $event) }}" class="btn-edit" style="text-decoration: none; display: inline-block; margin-right: 0.25rem;"><i class="fa-solid fa-eye"></i> Details</a>
                    @if($event->is_active)
                    <form method="POST" action="{{ route('admin.moderation.events.deactivate', $event) }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn-reject" onclick="return confirm('Deactivate this event?')"><i class="fa-solid fa-ban"></i> Deactivate</button>
                    </form>
                    @else
                    <form method="POST" action="{{ route('admin.moderation.events.activate', $event) }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn-approve"><i class="fa-solid fa-check"></i> Activate</button>
                    </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($events->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No events found.</p>
    @endif
</div>
@endsection
