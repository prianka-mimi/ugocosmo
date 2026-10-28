<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingCancelledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Booking $booking;

    public string $recipientName;

    public string $otherName;

    public string $cancellerName;

    public ?string $cancelNotes;

    public function __construct(Booking $booking, string $recipientName, string $otherName, string $cancellerName, ?string $cancelNotes = null)
    {
        $this->booking = $booking;
        $this->recipientName = $recipientName;
        $this->otherName = $otherName;
        $this->cancellerName = $cancellerName;
        $this->cancelNotes = $cancelNotes;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '❌ Booking Cancelled — UgoCosmo');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.cancelled',
            with: [
                'otherName' => $this->otherName,
                'cancellerName' => $this->cancellerName,
                'serviceName' => $this->booking->service?->title ?? 'Service',
                'bookingDate' => $this->booking->booking_date,
                'bookingTime' => $this->booking->booking_time,
                'cancelNotes' => $this->cancelNotes,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
