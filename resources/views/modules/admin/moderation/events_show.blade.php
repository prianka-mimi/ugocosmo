@extends('layouts.dashboard')

@section('page-title', 'Event Details - #' . $event->id)

@section('content')
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-calendar-days" style="color: #0d9488; margin-right: 0.5rem;"></i> Event Moderation Details</h3>
        <div class="card-header-actions">
            <a href="{{ route('admin.moderation.events') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #f3f4f6; color: #374151; font-weight: 500; font-size: 0.875rem;"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    {{-- Organizer Info Header --}}
    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; padding-bottom: 1.5rem;">
        @if($event->organizer?->avatar)
            <img src="{{ asset('storage/' . $event->organizer->avatar) }}" alt="" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;"/>
        @else
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #f3f4f6; display: flex; align-items: center; justify-content: center; border: 2px solid #e5e7eb;">
                <i class="fa-solid fa-user" style="font-size: 1.25rem; color: #9ca3af;"></i>
            </div>
        @endif
        <div>
            <div style="font-weight: 600; font-size: 1.05rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>{{ $event->organizer?->full_name ?? 'Unknown Organizer' }}</span>
                @if($event->organizer?->user)
                    <a href="{{ route('admin.users.show', $event->organizer->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; font-weight: normal;"><i class="fa-solid fa-external-link"></i> View Organizer Profile</a>
                @endif
            </div>
            <div style="font-size: 0.8125rem; color: #6b7280;">
                @if($event->organizer?->username) @ {{ $event->organizer->username }} &bull; @endif 
                {{ $event->organizer?->email ?? ($event->organizer?->user?->email ?? 'No Email') }}
            </div>
            <div style="display: flex; gap: 0.5rem; margin-top: 0.35rem; align-items: center;">
                @if($event->is_active)
                    <span class="badge" style="background: #f0fdf4; color: #166534; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Active</span>
                @else
                    <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Deactivated</span>
                @endif
                <span style="font-size: 0.75rem; color: #9ca3af;">Created: {{ $event->created_at->format('d M Y, h:i A') }}</span>
            </div>
        </div>
    </div>

    {{-- Event Core Info --}}
    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
        @if($event->image_url)
            <div style="max-width: 500px; border-radius: 0.5rem; overflow: hidden; border: 1px solid #e5e7eb;">
                <img src="{{ asset('storage/' . $event->image_url) }}" alt="{{ $event->title }}" style="width: 100%; height: auto; object-fit: cover;"/>
            </div>
        @endif
        
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #1f2937; margin: 0 0 0.25rem 0;">{{ $event->title }}</h2>
                <div style="font-size: 0.875rem; color: #ef4444; font-weight: 600;"><i class="fa-regular fa-clock"></i> Date & Time: {{ $event->event_date ? $event->event_date->format('d M Y, h:i A') : 'TBD' }}</div>
                <div style="font-size: 0.875rem; color: #4b5563; font-weight: 500; margin-top: 0.15rem;"><i class="fa-solid fa-location-dot"></i> Venue: <span style="color: #111827; font-weight: 600;">{{ $event->venue ?? 'N/A' }} ({{ $event->city }}, {{ $event->country }})</span></div>
            </div>

            <div style="font-size: 1.25rem; font-weight: 700; color: #0d9488; margin-top: 0.5rem;">
                Price: {{ $event->price == 0 ? 'Free' : '£' . number_format($event->price, 2) }}
            </div>

            <div style="background: #f9fafb; padding: 1rem; border-radius: 0.5rem; border: 1px solid #f3f4f6; margin-top: 0.5rem;">
                <div style="font-weight: 600; font-size: 0.75rem; color: #9ca3af; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Event Description</div>
                <div style="font-size: 0.875rem; line-height: 1.6; color: #374151; white-space: pre-wrap;">{{ $event->description ?? 'No description provided.' }}</div>
            </div>
        </div>
    </div>

    {{-- Moderation Controls --}}
    <div style="border-top: 1px solid #f3f4f6; padding-top: 1.5rem; display: flex; gap: 0.75rem;">
        @if($event->is_active)
            <form method="POST" action="{{ route('admin.moderation.events.deactivate', $event) }}">
                @csrf
                <button type="submit" class="btn-reject" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;" onclick="return confirm('Deactivate this event?')">
                    <i class="fa-solid fa-ban"></i> Deactivate Event
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.moderation.events.activate', $event) }}">
                @csrf
                <button type="submit" class="btn-approve" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;">
                    <i class="fa-solid fa-check"></i> Activate Event
                </button>
            </form>
        @endif
    </div>
</div>

{{-- Event Tickets List --}}
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1rem; font-weight: 600; color: #1b1b18; margin: 0;"><i class="fa-solid fa-ticket" style="color: #0d9488; margin-right: 0.5rem;"></i> Event Tickets ({{ $event->tickets->count() }})</h3>
    </div>
    
    @if($event->tickets->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem; margin: 0;">No tickets purchased for this event yet.</p>
    @else
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left;">
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Ticket Number</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Customer Name</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Customer Email</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Status</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Purchased At</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; text-align: right; border-bottom: 2px solid #e5e7eb; width: 160px;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($event->tickets as $ticket)
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #111827; font-family: monospace;">
                            {{ $ticket->ticket_number }}
                        </td>
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #111827;">
                            {{ $ticket->customer_name }}
                        </td>
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.8125rem; color: #4b5563;">
                            {{ $ticket->customer_email }}
                        </td>
                        <td style="padding: 0.75rem 0.5rem;">
                            @if($ticket->status === 'confirmed')
                                <span class="badge" style="background: #f0fdf4; color: #166534;">Confirmed</span>
                            @elseif($ticket->status === 'cancelled')
                                <span class="badge" style="background: #fef2f2; color: #b91c1c;">Cancelled</span>
                            @else
                                <span class="badge" style="background: #fef3c7; color: #d97706;">{{ ucfirst($ticket->status) }}</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.8125rem; color: #4b5563;">
                            {{ $ticket->created_at->format('Y-m-d H:i') }}
                        </td>
                        <td style="padding: 0.75rem 0.5rem; text-align: right; white-space: nowrap;">
                            <a href="{{ route('admin.moderation.tickets.show', $ticket) }}" class="btn-edit" style="text-decoration: none; font-size: 0.75rem; padding: 0.25rem 0.5rem;"><i class="fa-solid fa-eye"></i> View Ticket</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
