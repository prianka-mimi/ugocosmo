@extends('layouts.dashboard')

@section('page-title', 'Ticket Details - #' . $ticket->id)

@section('content')
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-ticket" style="color: #0d9488; margin-right: 0.5rem;"></i> Event Ticket Moderation</h3>
        <div class="card-header-actions">
            <a href="{{ route('admin.moderation.tickets') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #f3f4f6; color: #374151; font-weight: 500; font-size: 0.875rem;"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom: 1.5rem;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-size: 0.8125rem;">{{ session('error') }}</div>
    @endif

    {{-- Ticket Information Grid --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; padding-bottom: 1.5rem;">
        {{-- Customer details --}}
        <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
            <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em;"><i class="fa-solid fa-user"></i> Customer Info (Registered Account)</div>
            @if($ticket->customer)
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    @if($ticket->customer->avatar_url)
                        <img src="{{ asset('storage/' . $ticket->customer->avatar_url) }}" alt="" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 1px solid #e5e7eb;"/>
                    @else
                        <div style="width: 44px; height: 44px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid fa-user" style="color: #9ca3af; font-size: 1rem;"></i>
                        </div>
                    @endif
                    <div>
                        <div style="font-weight: 600; font-size: 0.9rem; color: #111827;">{{ $ticket->customer->full_name }}</div>
                        <div style="font-size: 0.8rem; color: #6b7280;">
                            @if($ticket->customer->username) @ {{ $ticket->customer->username }} &bull; @endif
                            {{ $ticket->customer->email ?? ($ticket->customer->user?->email ?? 'No email') }}
                        </div>
                        @if($ticket->customer->user)
                            <a href="{{ route('admin.users.show', $ticket->customer->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; display: inline-block; margin-top: 0.25rem;"><i class="fa-solid fa-external-link"></i> View Customer User</a>
                        @endif
                    </div>
                </div>
            @else
                <div style="font-size: 0.875rem; color: #6b7280;">Purchased as Guest or Account deleted.</div>
            @endif
        </div>

        {{-- Ticket Holder Info (Written at purchase) --}}
        <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
            <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em;"><i class="fa-solid fa-address-card"></i> Ticket Holder Info</div>
            <div style="font-size: 0.875rem; display: flex; flex-direction: column; gap: 0.35rem;">
                <div><strong>Name:</strong> {{ $ticket->customer_name }}</div>
                <div><strong>Email:</strong> {{ $ticket->customer_email }}</div>
                @if($ticket->customer_dob)
                    <div><strong>DOB:</strong> {{ $ticket->customer_dob->format('d M Y') }}</div>
                @endif
                @if($ticket->customer_address)
                    <div><strong>Address:</strong> {{ $ticket->customer_address }}</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Event details --}}
    <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
        <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
            <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Event details</div>
            @if($ticket->event)
                <div style="font-size: 1.05rem; font-weight: 700; color: #111827;">{{ $ticket->event->title }}</div>
                <div style="font-size: 0.8125rem; color: #4b5563; margin-top: 0.25rem;">
                    Venue: <strong>{{ $ticket->event->venue }}</strong> &bull; Date: <strong>{{ $ticket->event->start_date ? \Carbon\Carbon::parse($ticket->event->start_date)->format('d M Y') : 'N/A' }}</strong>
                </div>
                <a href="{{ route('admin.moderation.events.show', $ticket->event) }}" style="font-size: 0.78rem; text-decoration: none; color: #3b82f6; display: inline-block; margin-top: 0.5rem;"><i class="fa-solid fa-calendar-days"></i> View Event Moderation Page</a>
            @else
                <div style="color: #dc2626; font-size: 0.875rem; font-weight: 600;">Event was deleted or is not available.</div>
            @endif
        </div>

        {{-- Ticket details --}}
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Ticket Number</div>
                <div style="font-size: 1rem; font-weight: 700; color: #111827; font-family: monospace;">
                    {{ $ticket->ticket_number }}
                </div>
            </div>

            <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Section / Seat</div>
                <div style="font-size: 0.9rem; font-weight: 700; color: #111827;">
                    Section: {{ $ticket->section?->name ?? 'N/A' }}
                    @if($ticket->seat)
                        &bull; Seat: {{ $ticket->seat->seat_number }}
                    @endif
                </div>
            </div>

            <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Status</div>
                <div style="margin-top: 0.25rem;">
                    @if($ticket->status === 'confirmed')
                        <span class="badge" style="background: #f0fdf4; color: #166534; font-size: 0.8rem; padding: 0.25rem 0.6rem;">Confirmed</span>
                    @elseif($ticket->status === 'cancelled')
                        <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.8rem; padding: 0.25rem 0.6rem;">Cancelled</span>
                    @else
                        <span class="badge" style="background: #fef3c7; color: #d97706; font-size: 0.8rem; padding: 0.25rem 0.6rem;">{{ ucfirst($ticket->status) }}</span>
                    @endif
                </div>
            </div>

            <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Purchased At</div>
                <div style="font-size: 0.9rem; font-weight: 700; color: #111827;">
                    {{ $ticket->purchased_at ? $ticket->purchased_at->format('d M Y, h:i A') : $ticket->created_at->format('d M Y, h:i A') }}
                </div>
            </div>
        </div>

        {{-- Used Status --}}
        @if($ticket->used_at)
            <div style="background: #fdf2f2; padding: 1rem; border-radius: 0.5rem; border: 1px solid #fca5a5; display: flex; align-items: center; gap: 0.5rem; color: #991b1b;">
                <i class="fa-solid fa-circle-check"></i>
                <div style="font-size: 0.875rem; font-weight: 600;">
                    This ticket has been used/validated on {{ $ticket->used_at->format('d M Y, h:i A') }}
                </div>
            </div>
        @endif
    </div>

    {{-- Moderation Controls --}}
    <div style="border-top: 1px solid #f3f4f6; padding-top: 1.5rem; display: flex; gap: 0.75rem;">
        @if($ticket->status !== 'cancelled')
            <form method="POST" action="{{ route('admin.moderation.tickets.deactivate', $ticket) }}">
                @csrf
                <button type="submit" class="btn-reject" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;" onclick="return confirm('Cancel this ticket?')">
                    <i class="fa-solid fa-ban"></i> Cancel Ticket
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.moderation.tickets.activate', $ticket) }}">
                @csrf
                <button type="submit" class="btn-approve" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;">
                    <i class="fa-solid fa-check"></i> Confirm Ticket
                </button>
            </form>
        @endif
    </div>
</div>
@endsection
