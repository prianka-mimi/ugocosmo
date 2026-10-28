@extends('emails.layout')

@section('title', 'Booking Completed')
@section('subtitle', 'Your service booking has been completed')

@section('content')
    <h2>🎉 Booking Completed</h2>

    <p>Hello <strong>{{ $customerName }}</strong>,</p>

    <p>Your booking for <strong>{{ $serviceName }}</strong> has been marked as <strong>completed</strong>.</p>

    <div class="details">
        <table>
            <tr><td>Service</td><td>{{ $serviceName }}</td></tr>
            <tr><td>Provider</td><td>{{ $providerName }}</td></tr>
            <tr><td>Date</td><td>{{ $bookingDate }}</td></tr>
        </table>
    </div>

    <p>We hope you had a great experience! Please consider leaving a review to help other users.</p>

    <p style="text-align: center;">
        <a class="cta-button" href="{{ config('app.url') }}/bookings/{{ $bookingId }}">Leave a Review</a>
    </p>
@endsection
