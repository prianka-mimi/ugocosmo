<?php

namespace App\Manager;

use App\Models\Booking;
use App\Models\ProviderAvailability;
use App\Models\ProviderBlockedDate;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AvailabilityManager
{
    // ─── Provider manages their own availability ─────────────────

    public function setSlots(array $data): array
    {
        $profileId = (int) $this->profileId();
        $slots = $data['slots'] ?? [];

        if (array_key_exists('day_of_week', $data) && $data['day_of_week'] !== null) {
            $dayOfWeek = (int) $data['day_of_week'];

            // Determine default availability for this day's slots
            $dayOfWeekIsAvailable = isset($data['is_available'])
                ? filter_var($data['is_available'], FILTER_VALIDATE_BOOLEAN)
                : true;

            // Delete all existing slots for this day of week
            ProviderAvailability::byProvider($profileId)
                ->byDay($dayOfWeek)
                ->delete();

            $created = [];
            foreach ($slots as $slot) {
                // Slot value overrides root level availability if set
                $slotIsAvailable = isset($slot['is_available'])
                    ? filter_var($slot['is_available'], FILTER_VALIDATE_BOOLEAN)
                    : $dayOfWeekIsAvailable;

                $availability = ProviderAvailability::create([
                    'provider_id' => $profileId,
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'is_available' => $slotIsAvailable,
                ]);
                $created[] = $availability;
            }

            return $created;
        } else {
            // Delete all existing slots for this provider (sync entire schedule)
            ProviderAvailability::byProvider($profileId)->delete();

            $created = [];
            foreach ($slots as $slot) {
                $availability = ProviderAvailability::create([
                    'provider_id' => $profileId,
                    'day_of_week' => $slot['day_of_week'],
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'is_available' => isset($slot['is_available']) ? filter_var($slot['is_available'], FILTER_VALIDATE_BOOLEAN) : true,
                ]);
                $created[] = $availability;
            }

            return $created;
        }
    }

    // ─── Provider manages date blocking ───────────────────────────

    public function blockDate(array $data): ProviderBlockedDate
    {
        $profileId = (int) $this->profileId();

        return ProviderBlockedDate::create([
            'provider_id' => $profileId,
            'blocked_date' => $data['blocked_date'],
            'start_time' => $data['start_time'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'reason' => $data['reason'] ?? null,
        ]);
    }

    public function getBlockedDates(): array
    {
        $profileId = (int) $this->profileId();

        return ProviderBlockedDate::byProvider($profileId)
            ->orderBy('blocked_date', 'desc')
            ->get()
            ->all();
    }

    public function unblockDate(ProviderBlockedDate $blockedDate): void
    {
        if ((int) $blockedDate->provider_id !== (int) $this->profileId()) {
            abort(404);
        }

        $blockedDate->delete();
    }

    public function myAvailability(array $params = [], int $perPage = 20): array
    {
        $profileId = (int) $this->profileId();

        return ProviderAvailability::byProvider($profileId)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->all();
    }

    public function updateSlot(ProviderAvailability $slot, array $data): ProviderAvailability
    {
        $this->ensureOwned($slot);

        $fillable = ['start_time', 'end_time', 'is_available'];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $slot->$field = $data[$field];
            }
        }

        $slot->save();

        return $slot->fresh();
    }

    public function removeSlot(ProviderAvailability $slot): void
    {
        $this->ensureOwned($slot);
        $slot->delete();
    }

    // ─── Check availability for a service (public) ───────────────

    public function checkSlots(Service $service, string $date): array
    {
        $providerId = (int) $service->provider_id;
        $dayOfWeek = Carbon::parse($date)->dayOfWeek;
        $dateStr = Carbon::parse($date)->toDateString();

        $availableSlots = ProviderAvailability::byProvider($providerId)
            ->byDay($dayOfWeek)
            ->available()
            ->orderBy('start_time')
            ->get();

        $bookings = Booking::byProvider($providerId)
            ->where('booking_date', $dateStr)
            ->whereIn('status', ['pending', 'confirmed'])
            ->get();

        // Fetch blocked dates/times for this date
        $blocks = ProviderBlockedDate::byProvider($providerId)
            ->byDate($dateStr)
            ->get();

        $slots = [];

        foreach ($availableSlots as $slot) {
            // Check if slot overlaps with any block
            $isBlocked = $blocks->contains(function (ProviderBlockedDate $block) use ($slot) {
                // If the entire day is blocked (no start/end time defined)
                if (empty($block->start_time) && empty($block->end_time)) {
                    return true;
                }

                // If specific time range is blocked, check for overlap
                $bStart = $block->start_time;
                $bEnd = $block->end_time;
                $sStart = $slot->start_time;
                $sEnd = $slot->end_time;

                return $sStart < $bEnd && $sEnd > $bStart;
            });

            $isBooked = $bookings->contains(function (Booking $booking) use ($slot) {
                return $booking->booking_time >= $slot->start_time
                    && $booking->booking_time < $slot->end_time;
            });

            $slots[] = [
                'start_time' => substr($slot->start_time, 0, 5),
                'end_time' => substr($slot->end_time, 0, 5),
                'is_available' => ! $isBooked && ! $isBlocked,
            ];
        }

        return $slots;
    }

    // ─── Helpers ──────────────────────────────────────────────────

    protected function ensureOwned(ProviderAvailability $slot): void
    {
        if ((int) $slot->provider_id !== (int) $this->profileId()) {
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
