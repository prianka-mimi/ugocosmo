<?php

namespace App\Manager;

use App\Models\Booking;
use App\Models\NotificationPreference;
use App\Models\ProviderAvailability;
use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class BookingManager
{
    protected const BUYER_FEE_RATE = 0.05;

    protected const SELLER_FEE_RATE = 0.05;

    protected const PLATFORM_FEE_RATE = 0.10;

    protected NotificationManager $notificationManager;

    protected EmailNotificationService $emailService;

    public function __construct(NotificationManager $notificationManager, EmailNotificationService $emailService)
    {
        $this->notificationManager = $notificationManager;
        $this->emailService = $emailService;
    }

    /**
     * Create a new booking and notify the provider.
     */
    public function create(array $data): Booking
    {
        $profileId = (int) $this->profileId();
        $service = Service::findOrFail($data['service_id']);

        if (! $service->is_active) {
            throw new \Exception('This service is no longer available', 400);
        }

        if ((int) $service->provider_id === $profileId) {
            throw new \Exception('You cannot book your own service', 422);
        }

        $bookingDate = $data['booking_date'] ?? null;
        $bookingTime = $data['booking_time'] ?? null;

        if ($service->service_type === 'online' && (empty($bookingDate) || empty($bookingTime))) {
            $dateStr = null;
            $bookingTime = null;
        } else {
            if (empty($bookingDate) || empty($bookingTime)) {
                throw new \Exception('Booking date and time are required.', 422);
            }

            $dateStr = Carbon::parse($bookingDate)->toDateString();
            $dayOfWeek = Carbon::parse($bookingDate)->dayOfWeek;

            $availableSlot = ProviderAvailability::byProvider((int) $service->provider_id)
                ->byDay($dayOfWeek)
                ->available()
                ->where('start_time', '<=', $bookingTime)
                ->where('end_time', '>', $bookingTime)
                ->first();

            if (! $availableSlot) {
                throw new \Exception('The requested time slot is not available', 422);
            }

            $conflicting = Booking::byProvider((int) $service->provider_id)
                ->where('booking_date', $dateStr)
                ->where('booking_time', $bookingTime)
                ->whereIn('status', ['pending', 'confirmed'])
                ->first();

            if ($conflicting) {
                throw new \Exception('This time slot is already booked', 422);
            }

            $ownConflicting = Booking::byCustomer($profileId)
                ->where('booking_date', $dateStr)
                ->where('booking_time', $bookingTime)
                ->whereIn('status', ['pending', 'confirmed'])
                ->first();

            if ($ownConflicting) {
                throw new \Exception('You already have a booking at this time', 422);
            }
        }

        // Resolve pricing
        $unitPrice = (float) $service->price;
        if (! empty($data['package_id'])) {
            $package = ServicePackage::find($data['package_id']);
            if ($package) {
                $unitPrice += (float) $package->price;
            }
        }

        $duration = isset($data['duration']) ? (int) $data['duration'] : 1;
        $subtotal = $unitPrice * $duration;

        $buyerFee = round($subtotal * self::BUYER_FEE_RATE, 2);
        $sellerFee = round($subtotal * self::SELLER_FEE_RATE, 2);
        $platformFee = round($subtotal * self::PLATFORM_FEE_RATE, 2);
        $total = round($subtotal + $buyerFee + $platformFee, 2);

        // Resolve delivery location details
        $deliveryLocation = null;
        $customerAddress = null;

        if ($service->service_type !== 'online') {
            if ($service->delivery_location === 'At customer\'s address (I go to them)') {
                $deliveryLocation = 'At customer\'s address (I go to them)';
            } elseif ($service->delivery_location === 'At my location (customer comes to me)') {
                $deliveryLocation = 'At my location (customer comes to me)';
            } else {
                $deliveryLocation = $data['delivery_location'] ?? null;
            }
            $customerAddress = ($deliveryLocation === 'At customer\'s address (I go to them)') ? ($data['customer_address'] ?? null) : null;
        }

        // Handle uploaded images
        $uploadedImages = [];
        if (isset($data['images'])) {
            foreach ($data['images'] as $imageFile) {
                $uploadedImages[] = app(ImageManager::class)->upload($imageFile, 'bookings');
            }
        }

        // Determine status
        $status = ($service->service_type === 'online') ? 'in_cart' : 'pending';

        $booking = Booking::create([
            'service_id' => $service->id,
            'customer_id' => $profileId,
            'provider_id' => $service->provider_id,
            'booking_date' => $dateStr,
            'booking_time' => $bookingTime,
            'status' => $status,
            'notes' => $data['notes'] ?? null,
            'delivery_location' => $deliveryLocation,
            'customer_address' => $customerAddress,
            'package_id' => $data['package_id'] ?? null,
            'duration' => $duration,
            'images' => $uploadedImages,
            'subtotal' => $subtotal,
            'buyer_fee' => $buyerFee,
            'seller_fee' => $sellerFee,
            'platform_fee_total' => $platformFee,
            'total' => $total,
        ]);

        $booking = $booking->load(['service', 'customer', 'provider', 'package']);

        // If online service booking, add to cart automatically
        if ($status === 'in_cart') {
            app(CartManager::class)->add([
                'item_type' => 'service',
                'item_id' => $service->id,
                'booking_id' => $booking->id,
                'quantity' => 1,
            ]);
        } else {
            // ── Notify the provider ───────────────────────────────────
            $customerName = $booking->customer?->full_name ?? 'A user';
            $serviceName = $booking->service?->title ?? 'Service';
            $this->notificationManager->dispatch(
                (int) $booking->provider_id,
                'booking.created',
                'New Booking Received',
                "You have a new booking from {$customerName} for {$serviceName} on {$dateStr} at {$bookingTime}.",
                [
                    'booking_id' => $booking->id,
                    'service_id' => $service->id,
                    'customer_id' => $profileId,
                ]
            );

            // ── Send email to provider ───────────────────────────────
            $this->sendEmailIfEnabled(
                (int) $booking->provider_id,
                'booking.created',
                fn ($email, $name) => $this->emailService->bookingCreated($booking, $email, $name)
            );
        }

        return $booking;
    }

    /**
     * Activate a booking from 'in_cart' status to 'pending' during checkout, and notify provider.
     */
    public function activateBooking(Booking $booking): void
    {
        if ($booking->status !== 'in_cart') {
            return;
        }

        $booking->update(['status' => 'pending']);
        $booking = $booking->fresh(['service', 'customer', 'provider']);

        $customerName = $booking->customer?->full_name ?? 'A user';
        $serviceName = $booking->service?->title ?? 'Service';
        $dateStr = $booking->booking_date?->toDateString();
        $bookingTime = $booking->booking_time;

        $this->notificationManager->dispatch(
            (int) $booking->provider_id,
            'booking.created',
            'New Booking Received',
            "You have a new booking from {$customerName} for {$serviceName} on {$dateStr} at {$bookingTime}.",
            [
                'booking_id' => $booking->id,
                'service_id' => $booking->service_id,
                'customer_id' => $booking->customer_id,
            ]
        );

        $this->sendEmailIfEnabled(
            (int) $booking->provider_id,
            'booking.created',
            fn ($email, $name) => $this->emailService->bookingCreated($booking, $email, $name)
        );
    }

    public function myBookings(string $role = 'customer', int $perPage = 20)
    {
        $profileId = (int) $this->profileId();

        $query = Booking::with(['service', 'customer', 'provider'])
            ->orderBy('booking_date', 'desc')
            ->orderBy('booking_time', 'desc');

        if ($role === 'provider') {
            $query->byProvider($profileId);
        } else {
            $query->byCustomer($profileId);
        }

        return $query->paginate($perPage);
    }

    public function show(Booking $booking): Booking
    {
        $this->ensureParticipant($booking);

        return $booking->load(['service', 'customer', 'provider']);
    }

    /**
     * Cancel a booking and notify the other participant.
     */
    public function cancel(Booking $booking, ?string $notes = null): Booking
    {
        $this->ensureParticipant($booking);

        if (! in_array($booking->status, ['pending', 'confirmed'])) {
            throw new \Exception('This booking cannot be cancelled', 400);
        }

        $booking->update([
            'status' => 'cancelled',
            'notes' => $notes ? ($booking->notes ? $booking->notes."\n".$notes : $notes) : $booking->notes,
        ]);

        $booking = $booking->fresh()->load(['service', 'customer', 'provider']);

        $profileId = (int) $this->profileId();
        $cancellerName = $profileId === (int) $booking->customer_id
            ? $booking->customer?->full_name ?? 'The customer'
            : $booking->provider?->full_name ?? 'The provider';
        $recipientId = $profileId === (int) $booking->customer_id
            ? (int) $booking->provider_id
            : (int) $booking->customer_id;
        $serviceName = $booking->service?->title ?? 'Service';

        $this->notificationManager->dispatch(
            $recipientId,
            'booking.cancelled',
            'Booking Cancelled',
            "{$cancellerName} has cancelled the booking for {$serviceName} on {$booking->booking_date} at {$booking->booking_time}.",
            [
                'booking_id' => $booking->id,
                'service_id' => $booking->service_id,
            ]
        );

        // ── Send cancellation email ──────────────────────────────
        $otherName = $profileId === (int) $booking->customer_id
            ? ($booking->provider?->full_name ?? 'The provider')
            : ($booking->customer?->full_name ?? 'The customer');

        $this->sendEmailIfEnabled(
            $recipientId,
            'booking.cancelled',
            fn ($email, $name) => $this->emailService->bookingCancelled(
                $booking, $email, $name, $otherName, $cancellerName, $notes
            )
        );

        return $booking;
    }

    /**
     * Confirm a pending booking and notify the customer.
     */
    public function confirm(Booking $booking): Booking
    {
        $profileId = (int) $this->profileId();

        if ((int) $booking->provider_id !== $profileId) {
            throw new \Exception('Only the provider can confirm bookings', 403);
        }

        if ($booking->status !== 'pending') {
            throw new \Exception('This booking cannot be confirmed', 400);
        }

        $booking->update(['status' => 'confirmed']);

        $booking = $booking->fresh()->load(['service', 'customer', 'provider']);

        $serviceName = $booking->service?->title ?? 'Service';
        $this->notificationManager->dispatch(
            (int) $booking->customer_id,
            'booking.confirmed',
            'Booking Confirmed',
            "Your booking for {$serviceName} on {$booking->booking_date} at {$booking->booking_time} has been confirmed.",
            [
                'booking_id' => $booking->id,
                'service_id' => $booking->service_id,
            ]
        );

        // ── Send confirmation email ──────────────────────────────
        $this->sendEmailIfEnabled(
            (int) $booking->customer_id,
            'booking.confirmed',
            fn ($email, $name) => $this->emailService->bookingConfirmed($booking, $email, $name)
        );

        return $booking;
    }

    /**
     * Complete a confirmed or delivered booking (customer accepts delivery) and notify the provider.
     */
    public function complete(Booking $booking): Booking
    {
        $profileId = (int) $this->profileId();

        if ((int) $booking->customer_id !== $profileId) {
            throw new \Exception('Only the customer can complete bookings', 403);
        }

        if (! in_array($booking->status, ['confirmed', 'delivered'])) {
            throw new \Exception('Only confirmed or delivered bookings can be completed', 400);
        }

        $booking->update(['status' => 'completed']);

        $booking = $booking->fresh()->load(['service', 'customer', 'provider']);

        $serviceName = $booking->service?->title ?? 'Service';
        $this->notificationManager->dispatch(
            (int) $booking->provider_id,
            'booking.completed',
            'Booking Completed',
            "Your booking for {$serviceName} on {$booking->booking_date} has been marked as completed.",
            [
                'booking_id' => $booking->id,
                'service_id' => $booking->service_id,
            ]
        );

        // ── Send completion email ────────────────────────────────
        $this->sendEmailIfEnabled(
            (int) $booking->provider_id,
            'booking.completed',
            fn ($email, $name) => $this->emailService->bookingCompleted($booking, $email, $name)
        );

        return $booking;
    }

    /**
     * Reject a pending booking and notify the customer.
     */
    public function reject(Booking $booking): Booking
    {
        $profileId = (int) $this->profileId();

        if ((int) $booking->provider_id !== $profileId) {
            throw new \Exception('Only the provider can reject bookings', 403);
        }

        if ($booking->status !== 'pending') {
            throw new \Exception('Only pending bookings can be rejected', 400);
        }

        $booking->update(['status' => 'rejected']);

        $booking = $booking->fresh()->load(['service', 'customer', 'provider']);

        $serviceName = $booking->service?->title ?? 'Service';
        $this->notificationManager->dispatch(
            (int) $booking->customer_id,
            'booking.rejected',
            'Booking Rejected',
            "Your booking for {$serviceName} on {$booking->booking_date} has been rejected by the provider.",
            [
                'booking_id' => $booking->id,
                'service_id' => $booking->service_id,
            ]
        );

        // Notify customer via email if enabled
        $this->sendEmailIfEnabled(
            (int) $booking->customer_id,
            'booking.rejected',
            fn ($email, $name) => $this->emailService->bookingCancelled($booking, $email, $name, $booking->provider?->full_name, 'The provider', 'Booking rejected by provider.')
        );

        return $booking;
    }

    /**
     * Deliver a confirmed booking and notify the customer.
     */
    public function deliver(Booking $booking, array $data): Booking
    {
        $profileId = (int) $this->profileId();

        if ((int) $booking->provider_id !== $profileId) {
            throw new \Exception('Only the provider can deliver bookings', 403);
        }

        if ($booking->status !== 'confirmed') {
            throw new \Exception('Only confirmed bookings can be delivered', 400);
        }

        $uploadedFiles = [];
        if (isset($data['delivery_files'])) {
            foreach ($data['delivery_files'] as $file) {
                $uploadedFiles[] = app(ImageManager::class)->upload($file, 'deliveries');
            }
        }

        $booking->update([
            'status' => 'delivered',
            'delivery_text' => $data['delivery_text'] ?? null,
            'delivery_files' => $uploadedFiles,
        ]);

        $booking = $booking->fresh()->load(['service', 'customer', 'provider']);

        $serviceName = $booking->service?->title ?? 'Service';
        $this->notificationManager->dispatch(
            (int) $booking->customer_id,
            'booking.delivered',
            'Booking Delivered',
            "Your booking for {$serviceName} has been delivered by the provider. Please review the delivery.",
            [
                'booking_id' => $booking->id,
                'service_id' => $booking->service_id,
            ]
        );

        // Notify customer via email if enabled
        $this->sendEmailIfEnabled(
            (int) $booking->customer_id,
            'booking.delivered',
            fn ($email, $name) => $this->emailService->bookingConfirmed($booking, $email, $name)
        );

        return $booking;
    }

    /**
     * Reject a delivery (request revisions), append rejection reason to notes, and notify the provider.
     */
    public function rejectDelivery(Booking $booking, array $data): Booking
    {
        $profileId = (int) $this->profileId();

        if ((int) $booking->customer_id !== $profileId) {
            throw new \Exception('Only the customer can reject delivery', 403);
        }

        if ($booking->status !== 'delivered') {
            throw new \Exception('Only delivered bookings can have their delivery rejected', 400);
        }

        $reason = $data['rejection_reason'];

        // Revert status back to confirmed so provider can deliver again
        $booking->update([
            'status' => 'confirmed',
            'notes' => $booking->notes
                ? $booking->notes."\nDelivery Rejected Reason: ".$reason
                : 'Delivery Rejected Reason: '.$reason,
        ]);

        $booking = $booking->fresh()->load(['service', 'customer', 'provider']);

        $serviceName = $booking->service?->title ?? 'Service';
        $this->notificationManager->dispatch(
            (int) $booking->provider_id,
            'booking.revision_requested',
            'Revision Requested',
            "The customer requested revision/rejected the delivery for {$serviceName}.",
            [
                'booking_id' => $booking->id,
                'service_id' => $booking->service_id,
            ]
        );

        // Notify provider via email if enabled
        $this->sendEmailIfEnabled(
            (int) $booking->provider_id,
            'booking.revision_requested',
            fn ($email, $name) => $this->emailService->bookingCancelled($booking, $email, $name, $booking->customer?->full_name, 'The customer', 'Revision requested on delivery.')
        );

        return $booking;
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

    protected function ensureParticipant(Booking $booking): void
    {
        $profileId = (int) $this->profileId();

        if (
            (int) $booking->customer_id !== $profileId &&
            (int) $booking->provider_id !== $profileId
        ) {
            abort(404);
        }
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
