<?php

namespace App\Manager;

use App\Models\Event;
use App\Models\EventSeat;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TicketManager
{
    protected NotificationManager $notificationManager;

    protected EmailNotificationService $emailService;

    public function __construct(NotificationManager $notificationManager, EmailNotificationService $emailService)
    {
        $this->notificationManager = $notificationManager;
        $this->emailService = $emailService;
    }

    /**
     * Purchase a ticket for an event and notify the organizer.
     *
     * @param  int|null  $sectionId  Required if the event has sections.
     * @param  int|null  $seatId  Required if the chosen section has seats.
     */
    /**
     * Purchase a ticket for an event and notify the organizer.
     *
     * @param  int|null  $sectionId  Required if the event has sections.
     * @param  int|null  $seatId  Required if the chosen section has seats.
     */
    public function purchase(
        Event $event,
        ?string $customerName = null,
        ?string $customerEmail = null,
        ?string $customerDob = null,
        ?string $customerAddress = null,
        ?int $sectionId = null,
        ?int $seatId = null
    ): EventTicket {
        $profileId = (int) $this->profileId();
        $user = Auth::guard('jwt')->user();

        if (! $event->is_active) {
            throw new \Exception('This event is no longer available', 400);
        }

        if ($event->max_attendees) {
            $soldCount = EventTicket::forEvent((int) $event->id)->confirmed()->count();
            if ($soldCount >= $event->max_attendees) {
                throw new \Exception('This event is sold out', 400);
            }
        }

        // ── Validate section / seat selection ────────────────────
        $hasSections = $event->sections()->count() > 0;
        if ($hasSections) {
            if (! $sectionId) {
                throw new \Exception('This event has sections. Please select a section.', 422);
            }

            $section = EventSection::where('id', $sectionId)
                ->where('event_id', $event->id)
                ->first();

            if (! $section) {
                throw new \Exception('The selected section is invalid.', 422);
            }

            $hasSeats = $section->seats()->count() > 0;
            if ($hasSeats) {
                if (! $seatId) {
                    throw new \Exception('The selected section has numbered seats. Please select a seat.', 422);
                }

                $seat = EventSeat::where('id', $seatId)
                    ->where('section_id', $section->id)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->first();

                if (! $seat) {
                    throw new \Exception('The selected seat is unavailable or invalid.', 422);
                }
            }
        }

        // ── Mark seat as booked ───────────────────────────────────
        if (isset($seat)) {
            $seat->update(['status' => 'booked']);
        }

        do {
            if ($event->ticket_prefix) {
                $ticketNumber = strtoupper(trim($event->ticket_prefix)).'-'.mt_rand(10000000, 99999999);
            } else {
                $ticketNumber = strtoupper(Str::random(12));
            }
        } while (EventTicket::where('ticket_number', $ticketNumber)->exists());

        // Fallback user values if not provided
        $finalName = $customerName ?: ($user->profile?->full_name ?? $user->name ?? $user->email);
        $finalEmail = $customerEmail ?: $user->email;
        $finalDob = $customerDob ?: $user->dob;
        $finalAddress = $customerAddress ?: implode(', ', array_filter([
            $user->address_line_1,
            $user->address_line_2,
            $user->city,
            $user->postal_code,
            $user->country,
        ]));

        $ticket = EventTicket::create([
            'event_id' => $event->id,
            'customer_id' => $profileId,
            'section_id' => $sectionId,
            'seat_id' => $seatId,
            'ticket_number' => $ticketNumber,
            'customer_name' => $finalName,
            'customer_email' => $finalEmail,
            'customer_dob' => $finalDob,
            'customer_address' => $finalAddress,
            'status' => 'in_cart',
        ]);

        $ticket = $ticket->load(['event', 'customer', 'section', 'seat']);

        // Resolve CartManager to add the ticket to cart
        $cartManager = app(CartManager::class);
        $cartManager->add([
            'item_type' => 'ticket',
            'item_id' => $ticket->id,
            'quantity' => 1,
        ]);

        return $ticket;
    }

    /**
     * Confirm a ticket purchase after payment (checkout).
     */
    public function confirmTicket(EventTicket $ticket): void
    {
        $ticket->update([
            'status' => 'confirmed',
            'purchased_at' => now(),
        ]);

        $ticket->load(['event', 'customer', 'section', 'seat']);
        $event = $ticket->event;

        if ($event) {
            // ── Notify the event organizer ────────────────────────────
            $customerFullName = $ticket->customer_name ?? 'Someone';
            $this->notificationManager->dispatch(
                (int) $event->organizer_id,
                'ticket.purchased',
                'New Ticket Sold',
                "{$customerFullName} purchased a ticket for {$event->title}.",
                [
                    'ticket_id' => $ticket->id,
                    'event_id' => $event->id,
                ]
            );

            // ── Send email to organizer ───────────────────────────────
            $this->sendEmailIfEnabled(
                (int) $event->organizer_id,
                'ticket.purchased',
                fn ($email, $name) => $this->emailService->ticketPurchased(
                    $event, $ticket, $email, $name, $customerFullName
                )
            );
        }
    }

    /**
     * Purchase multiple tickets at once.
     */
    public function purchaseMultiple(Event $event, array $ticketsData): array
    {
        $created = [];
        foreach ($ticketsData as $ticketData) {
            $created[] = $this->purchase(
                $event,
                $ticketData['customer_name'] ?? null,
                $ticketData['customer_email'] ?? null,
                $ticketData['customer_dob'] ?? null,
                $ticketData['customer_address'] ?? null,
                $ticketData['section_id'] ?? null,
                $ticketData['seat_id'] ?? null
            );
        }

        return $created;
    }

    public function myTickets(int $perPage = 20)
    {
        $profileId = (int) $this->profileId();

        return EventTicket::byCustomer($profileId)
            ->latestFirst()
            ->with(['event', 'customer', 'section', 'seat'])
            ->paginate($perPage);
    }

    public function show(EventTicket $ticket): EventTicket
    {
        $this->ensureAccessible($ticket);

        return $ticket->load(['event', 'customer', 'section', 'seat']);
    }

    /**
     * Use (check-in) a ticket and notify the ticket holder.
     */
    public function useTicket(Event $event, EventTicket $ticket): EventTicket
    {
        $profileId = (int) $this->profileId();

        if ((int) $event->organizer_id !== $profileId) {
            throw new \Exception('Only the event organizer can check in tickets', 403);
        }

        if ((int) $ticket->event_id !== (int) $event->id) {
            abort(404);
        }

        if ($ticket->status !== 'confirmed') {
            throw new \Exception('This ticket has already been used or cancelled', 400);
        }

        $ticket->update([
            'status' => 'used',
            'used_at' => now(),
        ]);

        $ticket = $ticket->fresh()->load(['event', 'customer', 'section', 'seat']);

        // ── Notify the ticket holder ──────────────────────────────
        $this->notificationManager->dispatch(
            (int) $ticket->customer_id,
            'ticket.used',
            'Ticket Checked In',
            "Your ticket for {$event->title} has been checked in. Enjoy the event!",
            [
                'ticket_id' => $ticket->id,
                'event_id' => $event->id,
            ]
        );

        // ── Send email to ticket holder ───────────────────────────
        $this->sendEmailIfEnabled(
            (int) $ticket->customer_id,
            'ticket.used',
            fn ($email, $name) => $this->emailService->ticketUsed(
                $event, $ticket, $email, $name
            )
        );

        return $ticket;
    }

    public function eventTickets(Event $event)
    {
        $profileId = (int) $this->profileId();

        if ((int) $event->organizer_id !== $profileId) {
            throw new \Exception('Only the event organizer can view ticket sales', 403);
        }

        return EventTicket::forEvent((int) $event->id)
            ->latestFirst()
            ->with(['event', 'customer', 'section', 'seat'])
            ->get();
    }

    /**
     * Check the recipient's email preference and send the email if enabled.
     */
    protected function sendEmailIfEnabled(int $profileId, string $type, callable $sendFn): void
    {
        $prefs = NotificationPreference::forUser($profileId);
        if (! $prefs->isEmailEnabled($type)) {
            return;
        }

        $email = $this->emailService->getEmailForProfile($profileId);
        $name = $this->emailService->getNameForProfile($profileId);

        if ($email && $name) {
            $sendFn($email, $name);
        }
    }

    protected function ensureAccessible(EventTicket $ticket): void
    {
        $profileId = (int) $this->profileId();

        $event = $ticket->event;

        if (
            (int) $ticket->customer_id !== $profileId &&
            ($event && (int) $event->organizer_id !== $profileId)
        ) {
            abort(404);
        }
    }

    protected function profileName(): string
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        return $profile?->full_name ?? $user->email;
    }

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }
}
