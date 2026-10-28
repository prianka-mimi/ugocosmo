<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\EventTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketPurchasedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Event $event;

    public EventTicket $ticket;

    public string $organizerName;

    public string $customerName;

    public function __construct(Event $event, EventTicket $ticket, string $organizerName, string $customerName)
    {
        $this->event = $event;
        $this->ticket = $ticket;
        $this->organizerName = $organizerName;
        $this->customerName = $customerName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '🎫 New Ticket Sold — UgoCosmo');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket.purchased',
            with: [
                'organizerName' => $this->organizerName,
                'customerName' => $this->customerName,
                'eventTitle' => $this->event->title,
                'ticketNumber' => $this->ticket->ticket_number,
                'eventDate' => $this->event->event_date?->format('F j, Y g:i A'),
                'eventLocation' => $this->event->location ?? 'N/A',
                'eventId' => $this->event->id,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
