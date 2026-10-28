@extends('layouts.dashboard')

@section('page-title', 'Service Details - #' . $service->id)

@section('content')
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-server" style="color: #0d9488; margin-right: 0.5rem;"></i> Service Moderation Details</h3>
        <div class="card-header-actions">
            <a href="{{ route('admin.moderation.services') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #f3f4f6; color: #374151; font-weight: 500; font-size: 0.875rem;"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    {{-- Provider Info Header --}}
    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; padding-bottom: 1.5rem;">
        @if($service->provider?->avatar)
            <img src="{{ asset('storage/' . $service->provider->avatar) }}" alt="" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;"/>
        @else
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #f3f4f6; display: flex; align-items: center; justify-content: center; border: 2px solid #e5e7eb;">
                <i class="fa-solid fa-user" style="font-size: 1.25rem; color: #9ca3af;"></i>
            </div>
        @endif
        <div>
            <div style="font-weight: 600; font-size: 1.05rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>{{ $service->provider?->full_name ?? 'Unknown Provider' }}</span>
                @if($service->provider?->user)
                    <a href="{{ route('admin.users.show', $service->provider->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; font-weight: normal;"><i class="fa-solid fa-external-link"></i> View Provider Profile</a>
                @endif
            </div>
            <div style="font-size: 0.8125rem; color: #6b7280;">
                @if($service->provider?->username) @ {{ $service->provider->username }} &bull; @endif 
                {{ $service->provider?->email ?? ($service->provider?->user?->email ?? 'No Email') }}
            </div>
            <div style="display: flex; gap: 0.5rem; margin-top: 0.35rem; align-items: center;">
                @if($service->is_active)
                    <span class="badge" style="background: #f0fdf4; color: #166534; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Active</span>
                @else
                    <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Deactivated</span>
                @endif
                <span style="font-size: 0.75rem; color: #9ca3af;">Created: {{ $service->created_at->format('d M Y, h:i A') }}</span>
            </div>
        </div>
    </div>

    {{-- Service Core Info --}}
    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
        @if($service->image_url)
            <div style="max-width: 400px; border-radius: 0.5rem; overflow: hidden; border: 1px solid #e5e7eb;">
                <img src="{{ asset('storage/' . $service->image_url) }}" alt="{{ $service->title }}" style="width: 100%; height: auto; object-fit: cover;"/>
            </div>
        @endif
        
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #1f2937; margin: 0 0 0.25rem 0;">{{ $service->title }}</h2>
                <div style="font-size: 0.875rem; color: #4b5563; font-weight: 500;">
                    Category: <span style="color: #111827; font-weight: 600;">{{ $service->category?->name ?? 'N/A' }}</span> &bull; 
                    Type: <span style="text-transform: capitalize; color: #111827; font-weight: 600;">{{ $service->service_type }}</span>
                </div>
            </div>

            <div style="font-size: 1.25rem; font-weight: 700; color: #0d9488; margin-top: 0.5rem;">
                Starting Price: £{{ number_format($service->price ?? 0, 2) }}
            </div>

            <div style="background: #f9fafb; padding: 1rem; border-radius: 0.5rem; border: 1px solid #f3f4f6; margin-top: 0.5rem;">
                <div style="font-weight: 600; font-size: 0.75rem; color: #9ca3af; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Service Description</div>
                <div style="font-size: 0.875rem; line-height: 1.6; color: #374151; white-space: pre-wrap;">{{ $service->description ?? 'No description provided.' }}</div>
            </div>
            
            {{-- Service Packages (if any) --}}
            @if($service->packages && $service->packages->isNotEmpty())
                <div style="margin-top: 1rem;">
                    <div style="font-weight: 600; font-size: 0.8125rem; color: #4b5563; text-transform: uppercase; margin-bottom: 0.75rem;">Packages Detail</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                        @foreach($service->packages as $pkg)
                            <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem; background: #fff;">
                                <div style="font-weight: 700; font-size: 0.9rem; color: #1f2937; text-transform: uppercase; border-bottom: 1px solid #f3f4f6; padding-bottom: 0.25rem; margin-bottom: 0.5rem;">{{ $pkg->name }}</div>
                                <div style="font-size: 0.78rem; color: #6b7280; line-height: 1.5; margin-bottom: 0.75rem;">{{ $pkg->description }}</div>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.875rem;">
                                    <span style="color: #6b7280;">Price:</span>
                                    <span style="font-weight: 700; color: #0d9488;">£{{ number_format($pkg->price, 2) }}</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.8125rem; margin-top: 0.25rem; color: #6b7280;">
                                    <span>Delivery Time:</span>
                                    <span>{{ $pkg->delivery_days }} days</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Moderation Controls --}}
    <div style="border-top: 1px solid #f3f4f6; padding-top: 1.5rem; display: flex; gap: 0.75rem;">
        @if($service->is_active)
            <form method="POST" action="{{ route('admin.moderation.services.deactivate', $service) }}">
                @csrf
                <button type="submit" class="btn-reject" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;" onclick="return confirm('Deactivate this service?')">
                    <i class="fa-solid fa-ban"></i> Deactivate Service
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.moderation.services.activate', $service) }}">
                @csrf
                <button type="submit" class="btn-approve" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;">
                    <i class="fa-solid fa-check"></i> Activate Service
                </button>
            </form>
        @endif
    </div>
</div>

{{-- Bookings List --}}
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1rem; font-weight: 600; color: #1b1b18; margin: 0;"><i class="fa-solid fa-receipt" style="color: #0d9488; margin-right: 0.5rem;"></i> Service Bookings ({{ $service->bookings->count() }})</h3>
    </div>
    
    @if($service->bookings->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem; margin: 0;">No bookings received for this service yet.</p>
    @else
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left;">
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Booking ID</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Customer</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Booking Date &amp; Time</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Total Price</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Status</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; text-align: right; border-bottom: 2px solid #e5e7eb; width: 160px;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($service->bookings as $booking)
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #111827;">
                            #{{ $booking->id }}
                        </td>
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.875rem;">
                            <div style="font-weight: 600; color: #111827;">{{ $booking->customer?->full_name ?? 'Unknown' }}</div>
                            <div style="font-size: 0.75rem; color: #6b7280;">{{ $booking->customer?->email ?? ($booking->customer?->user?->email ?? 'No email') }}</div>
                        </td>
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.8125rem; color: #4b5563;">
                            {{ $booking->booking_date ? $booking->booking_date->format('Y-m-d') : 'N/A' }}
                            @if($booking->booking_time)
                                ({{ $booking->booking_time }})
                            @endif
                        </td>
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #0d9488;">
                            £{{ number_format($booking->total ?? 0, 2) }}
                        </td>
                        <td style="padding: 0.75rem 0.5rem;">
                            @if($booking->status === 'confirmed')
                                <span class="badge" style="background: #f0fdf4; color: #166534;">Confirmed</span>
                            @elseif($booking->status === 'cancelled')
                                <span class="badge" style="background: #fef2f2; color: #b91c1c;">Cancelled</span>
                            @else
                                <span class="badge" style="background: #fef3c7; color: #d97706;">{{ ucfirst($booking->status) }}</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem 0.5rem; text-align: right; white-space: nowrap;">
                            <a href="{{ route('admin.moderation.bookings.show', $booking) }}" class="btn-edit" style="text-decoration: none; font-size: 0.75rem; padding: 0.25rem 0.5rem;"><i class="fa-solid fa-eye"></i> View Booking</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
