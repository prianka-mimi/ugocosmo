@extends('emails.layout')

@section('title', 'Ticket Checked In')
@section('subtitle', 'Your ticket has been used — enjoy the event!')

@section('content')
    <h2>🎟️ Ticket Checked In</h2>

    <p>Hello <strong>{{ $customerName }}</strong>,</p>

    <p>Your ticket for <strong>{{ $eventTitle }}</strong> has been checked in. Enjoy the event!</p>

    <div class="details">
        <table>
            <tr><td>Event</td><td>{{ $eventTitle }}</td></tr>
            <tr><td>Ticket #</td><td>{{ $ticketNumber }}</td></tr>
            <tr><td>Date</td><td>{{ $eventDate }}</td></tr>
            <tr><td>Location</td><td>{{ $eventLocation }}</td></tr>
        </table>
    </div>

    <p>We hope you have a wonderful time at the event!</p>
@endsection
