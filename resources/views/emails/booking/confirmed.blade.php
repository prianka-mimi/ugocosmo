@extends('emails.layout')

@section('title', 'Booking Confirmed')
@section('subtitle', 'Your booking has been confirmed')

@section('content')
    <h2>✅ Booking Confirmed</h2>

    <p>Hello <strong>{{ $customerName }}</strong>,</p>

    <p>Your booking for <strong>{{ $serviceName }}</strong> has been <strong>confirmed</strong> by the provider.</p>

    <div class="details">
        <table>
            <tr><td>Service</td><td>{{ $serviceName }}</td></tr>
            <tr><td>Provider</td><td>{{ $providerName }}</td></tr>
            <tr><td>Date</td><td>{{ $bookingDate }}</td></tr>
            <tr><td>Time</td><td>{{ $bookingTime }}</td></tr>
            <tr><td>Total</td><td>${{ number_format($total, 2) }}</td></tr>
            <tr><td>Status</td><td>Confirmed</td></tr>
        </table>
    </div>

    <p>Please ensure you arrive on time for your appointment. If you need to cancel, please do so at least 24 hours in advance.</p>

    <p style="text-align: center;">
        <a class="cta-button" href="{{ config('app.url') }}/bookings/{{ $bookingId }}">View Booking Details</a>
    </p>
@endsection
