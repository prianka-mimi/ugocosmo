@extends('emails.layout')

@section('title', 'New Ticket Sold')
@section('subtitle', 'Someone purchased a ticket for your event')

@section('content')
    <h2>🎫 New Ticket Sold</h2>

    <p>Hello <strong>{{ $organizerName }}</strong>,</p>

    <p><strong>{{ $customerName }}</strong> has purchased a ticket for <strong>{{ $eventTitle }}</strong>.</p>

    <div class="details">
        <table>
            <tr><td>Event</td><td>{{ $eventTitle }}</td></tr>
            <tr><td>Customer</td><td>{{ $customerName }}</td></tr>
            <tr><td>Ticket #</td><td>{{ $ticketNumber }}</td></tr>
            <tr><td>Date</td><td>{{ $eventDate }}</td></tr>
            <tr><td>Location</td><td>{{ $eventLocation }}</td></tr>
        </table>
    </div>

    <p style="text-align: center;">
        <a class="cta-button" href="{{ config('app.url') }}/events/{{ $eventId }}/tickets">View Ticket Sales</a>
    </p>
@endsection
