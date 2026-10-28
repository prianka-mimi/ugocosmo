@extends('layouts.dashboard')

@section('page-title', 'Booking Details - #' . $booking->id)

@section('content')
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-receipt" style="color: #0d9488; margin-right: 0.5rem;"></i> Booking Moderation Details</h3>
        <div class="card-header-actions">
            <a href="{{ route('admin.moderation.bookings') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #f3f4f6; color: #374151; font-weight: 500; font-size: 0.875rem;"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom: 1.5rem;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-size: 0.8125rem;">{{ session('error') }}</div>
    @endif

    {{-- Party info: Customer vs Provider --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; padding-bottom: 1.5rem;">
        {{-- Customer block --}}
        <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
            <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em;"><i class="fa-solid fa-user"></i> Customer Information</div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                @if($booking->customer?->avatar_url)
                    <img src="{{ asset('storage/' . $booking->customer->avatar_url) }}" alt="" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 1px solid #e5e7eb;"/>
                @else
                    <div style="width: 44px; height: 44px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-user" style="color: #9ca3af; font-size: 1rem;"></i>
                    </div>
                @endif
                <div>
                    <div style="font-weight: 600; font-size: 0.9rem; color: #111827;">{{ $booking->customer?->full_name ?? 'Unknown Customer' }}</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">
                        @if($booking->customer?->username) @ {{ $booking->customer->username }} &bull; @endif
                        {{ $booking->customer?->email ?? ($booking->customer?->user?->email ?? 'No email') }}
                    </div>
                    @if($booking->customer?->user)
                        <a href="{{ route('admin.users.show', $booking->customer->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; display: inline-block; margin-top: 0.25rem;"><i class="fa-solid fa-external-link"></i> View User Account</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- Provider block --}}
        <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
            <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em;"><i class="fa-solid fa-user-tie"></i> Service Provider Information</div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                @if($booking->provider?->avatar_url)
                    <img src="{{ asset('storage/' . $booking->provider->avatar_url) }}" alt="" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 1px solid #e5e7eb;"/>
                @else
                    <div style="width: 44px; height: 44px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-user-tie" style="color: #9ca3af; font-size: 1rem;"></i>
                    </div>
                @endif
                <div>
                    <div style="font-weight: 600; font-size: 0.9rem; color: #111827;">{{ $booking->provider?->full_name ?? 'Unknown Provider' }}</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">
                        @if($booking->provider?->username) @ {{ $booking->provider->username }} &bull; @endif
                        {{ $booking->provider?->email ?? ($booking->provider?->user?->email ?? 'No email') }}
                    </div>
                    @if($booking->provider?->user)
                        <a href="{{ route('admin.users.show', $booking->provider->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; display: inline-block; margin-top: 0.25rem;"><i class="fa-solid fa-external-link"></i> View User Account</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Booking Detail --}}
    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            {{-- Service Card link --}}
            <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
                <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Booked Service</div>
                @if($booking->service)
                    <div style="font-size: 1rem; font-weight: 700; color: #111827;">{{ $booking->service->title }}</div>
                    <div style="font-size: 0.8125rem; color: #4b5563; margin-top: 0.25rem;">
                        Category: {{ $booking->service->category?->name ?? 'N/A' }} &bull; Type: {{ ucfirst($booking->service->service_type) }}
                    </div>
                    <a href="{{ route('admin.moderation.services.show', $booking->service) }}" style="font-size: 0.78rem; text-decoration: none; color: #3b82f6; display: inline-block; margin-top: 0.5rem;"><i class="fa-solid fa-server"></i> View Service Moderation Page</a>
                @else
                    <div style="color: #dc2626; font-size: 0.875rem; font-weight: 600;">Service was deleted or is not available.</div>
                @endif
            </div>

            {{-- Booking Details grid --}}
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                    <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Booking Date &amp; Time</div>
                    <div style="font-size: 0.9rem; font-weight: 700; color: #111827;">
                        {{ $booking->booking_date ? $booking->booking_date->format('d M Y') : 'N/A' }}
                        @if($booking->booking_time)
                            at {{ $booking->booking_time }}
                        @endif
                    </div>
                </div>

                <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                    <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Status</div>
                    <div style="margin-top: 0.25rem;">
                        @if($booking->status === 'confirmed')
                            <span class="badge" style="background: #f0fdf4; color: #166534; font-size: 0.8rem; padding: 0.25rem 0.6rem;">Confirmed</span>
                        @elseif($booking->status === 'cancelled')
                            <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.8rem; padding: 0.25rem 0.6rem;">Cancelled</span>
                        @else
                            <span class="badge" style="background: #fef3c7; color: #d97706; font-size: 0.8rem; padding: 0.25rem 0.6rem;">{{ ucfirst($booking->status) }}</span>
                        @endif
                    </div>
                </div>

                <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                    <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Total Payment</div>
                    <div style="font-size: 1.1rem; font-weight: 700; color: #0d9488;">
                        £{{ number_format($booking->total ?? 0, 2) }}
                    </div>
                </div>

                <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                    <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Duration</div>
                    <div style="font-size: 0.9rem; font-weight: 700; color: #111827;">
                        {{ $booking->duration ? $booking->duration . ' Hours' : 'N/A' }}
                    </div>
                </div>
            </div>

            {{-- Notes and location --}}
            @if($booking->notes)
                <div style="background: #f9fafb; padding: 1rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
                    <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem; letter-spacing: 0.05em;">Customer Notes</div>
                    <div style="font-size: 0.875rem; line-height: 1.5; color: #374151;">{{ $booking->notes }}</div>
                </div>
            @endif

            @if($booking->customer_address || $booking->delivery_location)
                <div style="background: #f9fafb; padding: 1rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
                    <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem; letter-spacing: 0.05em;">Location Details</div>
                    @if($booking->delivery_location)
                        <div style="font-size: 0.875rem; color: #374151; margin-bottom: 0.25rem;"><strong>Type:</strong> {{ ucfirst($booking->delivery_location) }}</div>
                    @endif
                    @if($booking->customer_address)
                        <div style="font-size: 0.875rem; color: #374151;"><strong>Address:</strong> {{ $booking->customer_address }}</div>
                    @endif
                </div>
            @endif

            {{-- Pricing Breakdown --}}
            <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
                <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em;">Financial Breakdown</div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.875rem;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #6b7280;">Subtotal:</span>
                        <span style="font-weight: 600; color: #111827;">£{{ number_format($booking->subtotal ?? 0, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #6b7280;">Buyer Fee:</span>
                        <span style="font-weight: 600; color: #111827;">£{{ number_format($booking->buyer_fee ?? 0, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #6b7280;">Seller Fee:</span>
                        <span style="font-weight: 600; color: #111827;">£{{ number_format($booking->seller_fee ?? 0, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #6b7280;">Platform Fee Total:</span>
                        <span style="font-weight: 600; color: #111827;">£{{ number_format($booking->platform_fee_total ?? 0, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 1px solid #e5e7eb; padding-top: 0.5rem; margin-top: 0.25rem; font-weight: 700; font-size: 0.95rem;">
                        <span style="color: #111827;">Grand Total:</span>
                        <span style="color: #0d9488;">£{{ number_format($booking->total ?? 0, 2) }}</span>
                    </div>
                </div>
            </div>

            {{-- Attached Images --}}
            @if($booking->images && is_array($booking->images) && count($booking->images) > 0)
                <div style="background: #f9fafb; padding: 1rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
                    <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Attached Images</div>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        @foreach($booking->images as $img)
                            <a href="{{ asset('storage/' . $img) }}" target="_blank">
                                <img src="{{ asset('storage/' . $img) }}" style="width: 100px; height: 100px; object-fit: cover; border-radius: 0.375rem; border: 1px solid #e5e7eb;"/>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Moderation Controls --}}
    <div style="border-top: 1px solid #f3f4f6; padding-top: 1.5rem; display: flex; gap: 0.75rem;">
        @if($booking->status !== 'cancelled')
            <form method="POST" action="{{ route('admin.moderation.bookings.deactivate', $booking) }}">
                @csrf
                <button type="submit" class="btn-reject" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;" onclick="return confirm('Cancel this booking?')">
                    <i class="fa-solid fa-ban"></i> Cancel Booking
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.moderation.bookings.activate', $booking) }}">
                @csrf
                <button type="submit" class="btn-approve" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;">
                    <i class="fa-solid fa-check"></i> Confirm Booking
                </button>
            </form>
        @endif
    </div>
</div>
@endsection
