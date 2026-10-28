@extends('emails.layout')

@section('title', 'New Booking Received')
@section('subtitle', 'You have a new booking request on UgoCosmo')

@section('content')
    <h2>📅 New Booking Received</h2>

    <p>Hello <strong>{{ $providerName }}</strong>,</p>

    <p><strong>{{ $customerName }}</strong> has booked your service <strong>{{ $serviceName }}</strong>.</p>

    <div class="details">
        <table>
            <tr><td>Service</td><td>{{ $serviceName }}</td></tr>
            <tr><td>Customer</td><td>{{ $customerName }}</td></tr>
            <tr><td>Date</td><td>{{ $bookingDate }}</td></tr>
            <tr><td>Time</td><td>{{ $bookingTime }}</td></tr>
            <tr><td>Total</td><td>${{ number_format($total, 2) }}</td></tr>
            <tr><td>Status</td><td>Pending — awaiting your confirmation</td></tr>
        </table>
    </div>

    <p>Please confirm or cancel this booking in your dashboard.</p>

    <p style="text-align: center;">
        <a class="cta-button" href="{{ config('app.url') }}/bookings/{{ $bookingId }}">View Booking</a>
    </p>
@endsection
