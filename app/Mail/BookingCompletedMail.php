<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Booking $booking;

    public string $recipientName;

    public function __construct(Booking $booking, string $recipientName)
    {
        $this->booking = $booking;
        $this->recipientName = $recipientName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '🎉 Booking Completed — UgoCosmo');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.completed',
            with: [
                'customerName' => $this->recipientName,
                'providerName' => $this->booking->provider?->full_name ?? 'The provider',
                'serviceName' => $this->booking->service?->title ?? 'Service',
                'bookingDate' => $this->booking->booking_date,
                'bookingId' => $this->booking->id,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
