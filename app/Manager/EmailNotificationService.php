<?php

namespace App\Manager;

use App\Mail\BookingCancelledMail;
use App\Mail\BookingCompletedMail;
use App\Mail\BookingConfirmedMail;
use App\Mail\BookingCreatedMail;
use App\Mail\NewMessageMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\TicketPurchasedMail;
use App\Mail\TicketUsedMail;
use App\Models\Booking;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Order;
use App\Models\Profile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * EmailNotificationService
 *
 * Sends transactional emails for bookings, tickets, and messages using
 * Laravel's Mail facade. Provider-agnostic — works with any mail driver
 * configured in config/mail.php (SendGrid, Mailgun, SES, Resend, SMTP, etc.).
 *
 * ─── Email Templates ───────────────────────────────────────────────
 * Booking:
 *   - booking.created    → emails.booking.created     (provider)
 *   - booking.confirmed  → emails.booking.confirmed   (customer)
 *   - booking.completed  → emails.booking.completed   (customer)
 *   - booking.cancelled  → emails.booking.cancelled   (other party)
 * Ticket:
 *   - ticket.purchased   → emails.ticket.purchased    (organizer)
 *   - ticket.used        → emails.ticket.used         (customer)
 * Message:
 *   - message.new        → emails.message.new         (recipient)
 *
 * ─── Setup ─────────────────────────────────────────────────────────
 * Configure your mail driver in .env. Examples:
 *
 *   # SendGrid (via SMTP)
 *   MAIL_MAILER=smtp
 *   MAIL_HOST=smtp.sendgrid.net
 *   MAIL_PORT=587
 *   MAIL_USERNAME=apikey
 *   MAIL_PASSWORD=SG.xxxxx
 *
 *   # Mailgun
 *   MAIL_MAILER=mailgun
 *   MAILGUN_DOMAIN=your-domain.com
 *   MAILGUN_SECRET=key-xxxxx
 *
 *   # Resend
 *   MAIL_MAILER=resend
 *   RESEND_API_KEY=re_xxxxx
 *
 *   # Amazon SES
 *   MAIL_MAILER=ses
 *   AWS_ACCESS_KEY_ID=xxxxx
 *   AWS_SECRET_ACCESS_KEY=xxxxx
 *   AWS_DEFAULT_REGION=us-east-1
 */
class EmailNotificationService
{
    /**
     * Send an email for a booking created event (→ provider).
     */
    public function bookingCreated(Booking $booking, string $providerEmail, string $providerName): void
    {
        try {
            Mail::to($providerEmail, $providerName)
                ->send(new BookingCreatedMail($booking, $providerName, 'provider'));
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService: Failed to send booking.created email', [
                'recipient' => $providerEmail,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an email for a booking confirmed event (→ customer).
     */
    public function bookingConfirmed(Booking $booking, string $customerEmail, string $customerName): void
    {
        try {
            Mail::to($customerEmail, $customerName)
                ->send(new BookingConfirmedMail($booking, $customerName));
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService: Failed to send booking.confirmed email', [
                'recipient' => $customerEmail,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an email for a booking completed event (→ customer).
     */
    public function bookingCompleted(Booking $booking, string $customerEmail, string $customerName): void
    {
        try {
            Mail::to($customerEmail, $customerName)
                ->send(new BookingCompletedMail($booking, $customerName));
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService: Failed to send booking.completed email', [
                'recipient' => $customerEmail,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an email for a booking cancelled event (→ the other participant).
     */
    public function bookingCancelled(Booking $booking, string $recipientEmail, string $recipientName, string $otherName, string $cancellerName, ?string $cancelNotes = null): void
    {
        try {
            Mail::to($recipientEmail, $recipientName)
                ->send(new BookingCancelledMail($booking, $recipientName, $otherName, $cancellerName, $cancelNotes));
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService: Failed to send booking.cancelled email', [
                'recipient' => $recipientEmail,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an email for a ticket purchased event (→ organizer).
     */
    public function ticketPurchased(Event $event, EventTicket $ticket, string $organizerEmail, string $organizerName, string $customerName): void
    {
        try {
            Mail::to($organizerEmail, $organizerName)
                ->send(new TicketPurchasedMail($event, $ticket, $organizerName, $customerName));
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService: Failed to send ticket.purchased email', [
                'recipient' => $organizerEmail,
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an email for a ticket used/checked-in event (→ customer).
     */
    public function ticketUsed(Event $event, EventTicket $ticket, string $customerEmail, string $customerName): void
    {
        try {
            Mail::to($customerEmail, $customerName)
                ->send(new TicketUsedMail($event, $ticket, $customerName));
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService: Failed to send ticket.used email', [
                'recipient' => $customerEmail,
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an email for a new message event (→ recipient).
     */
    public function newMessage(string $recipientEmail, string $recipientName, string $senderName, string $messageContent, int $conversationId): void
    {
        try {
            $preview = mb_substr($messageContent, 0, 200);
            Mail::to($recipientEmail, $recipientName)
                ->send(new NewMessageMail($recipientName, $senderName, $preview, $conversationId));
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService: Failed to send message.new email', [
                'recipient' => $recipientEmail,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an order confirmation email to the buyer.
     */
    public function orderConfirmation(Order $order, string $customerEmail, string $customerName): void
    {
        try {
            Mail::to($customerEmail, $customerName)
                ->send(new OrderConfirmationMail($order, $customerName));
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService: Failed to send order.confirmation email', [
                'recipient' => $customerEmail,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the email address for a given profile ID.
     */
    public function getEmailForProfile(int $profileId): ?string
    {
        $profile = Profile::find($profileId);

        return $profile?->email;
    }

    /**
     * Get the name for a given profile ID.
     */
    public function getNameForProfile(int $profileId): ?string
    {
        $profile = Profile::find($profileId);

        return $profile?->full_name;
    }
}
