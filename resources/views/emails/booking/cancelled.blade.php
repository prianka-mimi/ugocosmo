@extends('emails.layout')

@section('title', 'Booking Cancelled')
@section('subtitle', 'A booking has been cancelled')

@section('content')
    <h2>❌ Booking Cancelled</h2>

    <p>Hello <strong>{{ $otherName }}</strong>,</p>

    <p><strong>{{ $cancellerName }}</strong> has cancelled the booking for <strong>{{ $serviceName }}</strong>.</p>

    <div class="details">
        <table>
            <tr><td>Service</td><td>{{ $serviceName }}</td></tr>
            <tr><td>Date</td><td>{{ $bookingDate }}</td></tr>
            <tr><td>Time</td><td>{{ $bookingTime }}</td></tr>
            <tr><td>Status</td><td>Cancelled</td></tr>
        </table>
    </div>

    @if($cancelNotes)
        <p><strong>Cancellation reason:</strong> {{ $cancelNotes }}</p>
    @endif

    <p>If you have any questions, please contact the other party through the messaging system.</p>
@endsection
