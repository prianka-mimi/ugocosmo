<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailabilityIndexRequest;
use App\Http\Requests\BlockDateRequest;
use App\Http\Requests\SetAvailabilityRequest;
use App\Http\Requests\UpdateSlotRequest;
use App\Http\Resources\AvailabilityResource;
use App\Http\Resources\BlockedDateResource;
use App\Manager\AvailabilityManager;
use App\Models\ProviderAvailability;
use App\Models\ProviderBlockedDate;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AvailabilityController extends Controller
{
    protected AvailabilityManager $availabilityManager;

    public function __construct(AvailabilityManager $availabilityManager)
    {
        $this->availabilityManager = $availabilityManager;
    }

    public function setSlots(SetAvailabilityRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = AvailabilityResource::collection(
                $this->availabilityManager->setSlots($request->validated())
            );
            DB::commit();

            $this->status_message = 'Availability slots updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Set availability error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function myAvailability(AvailabilityIndexRequest $request): JsonResponse
    {
        try {
            $slots = $this->availabilityManager->myAvailability();
            $this->data = $this->formatWeeklySchedule($slots);
            $this->status_message = 'Availability retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My availability retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function updateSlot(UpdateSlotRequest $request, ProviderAvailability $slot): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = AvailabilityResource::make(
                $this->availabilityManager->updateSlot($slot, $request->validated())
            );
            DB::commit();

            $this->status_message = 'Availability slot updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Update availability error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function removeSlot(ProviderAvailability $slot): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->availabilityManager->removeSlot($slot);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Availability slot removed successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Remove availability error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function blockDate(BlockDateRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = BlockedDateResource::make(
                $this->availabilityManager->blockDate($request->validated())
            );
            DB::commit();

            $this->status_message = 'Date blocked successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Block date error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function getBlockedDates(): JsonResponse
    {
        try {
            $this->data = BlockedDateResource::collection(
                $this->availabilityManager->getBlockedDates()
            );
            $this->status_message = 'Blocked dates retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Get blocked dates error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function unblockDate(ProviderBlockedDate $blockedDate): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->availabilityManager->unblockDate($blockedDate);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Date unblocked successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Unblock date error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function checkSlots(Service $service): JsonResponse
    {
        try {
            $date = request()->query('date', now()->toDateString());

            $this->data = $this->availabilityManager->checkSlots($service, $date);
            $this->status_message = 'Available slots retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Check availability error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function weeklyAvailability(Service $service): JsonResponse
    {
        try {
            $providerId = (int) $service->provider_id;
            $slots = ProviderAvailability::byProvider($providerId)
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get()
                ->all();

            $this->data = $this->formatWeeklySchedule($slots);
            $this->status_message = 'Weekly availability retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Weekly availability error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    private function formatWeeklySchedule(array $slots): array
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $schedule = [];

        // Group slots by day_of_week
        $grouped = collect($slots)->groupBy('day_of_week');

        for ($i = 0; $i < 7; $i++) {
            $daySlots = $grouped->get($i, collect());

            // The day is available if there are any slots that are available
            $isAvailable = $daySlots->contains('is_available', true);

            $formattedSlots = [];
            $slotCount = 0;

            if ($isAvailable) {
                $availableSlotsOnly = $daySlots->filter(fn ($slot) => (bool) $slot->is_available);
                $formattedSlots = $availableSlotsOnly->map(fn ($slot) => [
                    'id' => $slot->id,
                    'start_time' => $slot->start_time ? substr($slot->start_time, 0, 5) : null,
                    'end_time' => $slot->end_time ? substr($slot->end_time, 0, 5) : null,
                    'is_available' => true,
                ])->values()->all();

                $slotCount = count($formattedSlots);
            }

            $schedule[] = [
                'day_of_week' => $i,
                'day_name' => $days[$i],
                'is_available' => $isAvailable,
                'slot_count' => $slotCount,
                'slots' => $formattedSlots,
            ];
        }

        return $schedule;
    }

    public function getDayAvailability(int $dayOfWeek): JsonResponse
    {
        try {
            if ($dayOfWeek < 0 || $dayOfWeek > 6) {
                throw new \Exception('Invalid day of week. Must be between 0 and 6.', 422);
            }

            $profileId = (int) $this->profileId();
            $slots = ProviderAvailability::byProvider($profileId)
                ->byDay($dayOfWeek)
                ->orderBy('start_time')
                ->get()
                ->all();

            $formatted = $this->formatWeeklySchedule($slots);
            $dayData = $formatted[$dayOfWeek] ?? null;

            $this->data = $dayData;
            $this->status_message = 'Day availability retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Get day availability error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function toggleDayAvailability(Request $request, int $dayOfWeek): JsonResponse
    {
        try {
            if ($dayOfWeek < 0 || $dayOfWeek > 6) {
                throw new \Exception('Invalid day of week. Must be between 0 and 6.', 422);
            }

            $request->validate([
                'is_available' => ['required', 'boolean'],
            ]);

            $profileId = (int) $this->profileId();
            $isAvailable = filter_var($request->input('is_available'), FILTER_VALIDATE_BOOLEAN);

            // Update all slots for this provider on this day
            ProviderAvailability::byProvider($profileId)
                ->byDay($dayOfWeek)
                ->update(['is_available' => $isAvailable]);

            // Fetch the updated slots to return
            $slots = ProviderAvailability::byProvider($profileId)
                ->byDay($dayOfWeek)
                ->orderBy('start_time')
                ->get()
                ->all();

            $formatted = $this->formatWeeklySchedule($slots);
            $dayData = $formatted[$dayOfWeek] ?? null;

            $this->data = $dayData;
            $this->status_message = 'Day availability status updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Toggle day availability error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user?->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }
}
