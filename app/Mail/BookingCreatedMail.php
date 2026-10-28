<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Booking $booking;

    public string $recipientName;

    public string $role; // 'provider' (new booking) or 'customer' (confirmation)

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, string $recipientName, string $role = 'provider')
    {
        $this->booking = $booking;
        $this->recipientName = $recipientName;
        $this->role = $role;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '📅 New Booking Received — UgoCosmo',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.created',
            with: [
                'providerName' => $this->recipientName,
                'customerName' => $this->booking->customer?->full_name ?? 'A user',
                'serviceName' => $this->booking->service?->title ?? 'Service',
                'bookingDate' => $this->booking->booking_date,
                'bookingTime' => $this->booking->booking_time,
                'total' => $this->booking->total,
                'bookingId' => $this->booking->id,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
