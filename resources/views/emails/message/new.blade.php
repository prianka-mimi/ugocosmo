@extends('emails.layout')

@section('title', 'New Message')
@section('subtitle', 'You received a new message on UgoCosmo')

@section('content')
    <h2>💬 New Message</h2>

    <p>Hello <strong>{{ $recipientName }}</strong>,</p>

    <p><strong>{{ $senderName }}</strong> sent you a message.</p>

    <div class="details">
        <table>
            <tr><td>From</td><td>{{ $senderName }}</td></tr>
            <tr><td>Message</td><td style="font-style: italic;">{{ $messagePreview }}</td></tr>
        </table>
    </div>

    <p style="text-align: center;">
        <a class="cta-button" href="{{ config('app.url') }}/conversations/{{ $conversationId }}">View Message</a>
    </p>

    <p style="font-size: 13px; color: #8888aa;">
        Please do not reply to this email. To respond, open the conversation in the app.
    </p>
@endsection
