<?php

namespace App\Http\Controllers;

use App\Models\SavedAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Buyer saved address book.
 *
 * A buyer can store multiple shipping addresses (e.g. Home, Office) and
 * mark one as the default to pre-fill checkout forms.
 */
class SavedAddressController extends Controller
{
    /**
     * GET /api/user/addresses
     *
     * List all saved addresses for the authenticated buyer.
     */
    public function index(): JsonResponse
    {
        try {
            $profile = $this->profile();
            $addresses = SavedAddress::where('profile_id', $profile->id)
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->get();

            $this->data = $addresses;
            $this->status_message = 'Saved addresses retrieved.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/user/addresses
     *
     * Save a new shipping address.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $profile = $this->profile();

            $validated = $request->validate([
                'label' => ['nullable', 'string', 'max:100'],
                'name' => ['required', 'string', 'max:255'],
                'address_line1' => ['required', 'string', 'max:500'],
                'address_line2' => ['nullable', 'string', 'max:500'],
                'city_locality' => ['required', 'string', 'max:255'],
                'state_province' => ['nullable', 'string', 'max:255'],
                'postal_code' => ['required', 'string', 'max:20'],
                'country_code' => ['nullable', 'string', 'max:10'],
                'phone' => ['nullable', 'string', 'max:50'],
                'is_default' => ['nullable', 'boolean'],
            ]);

            $validated['profile_id'] = $profile->id;
            $validated['country_code'] = $validated['country_code'] ?? 'US';
            $isDefault = (bool) ($validated['is_default'] ?? false);

            if ($isDefault) {
                SavedAddress::where('profile_id', $profile->id)->update(['is_default' => false]);
            } else {
                // If this is the very first address, automatically make it default
                $count = SavedAddress::where('profile_id', $profile->id)->count();
                if ($count === 0) {
                    $isDefault = true;
                }
            }

            $address = SavedAddress::create(array_merge($validated, ['is_default' => $isDefault]));

            if ($isDefault) {
                $this->syncUserAddress($profile);
            }

            $this->data = $address;
            $this->status_message = 'Address saved.';
            $this->status_code = 201;
        } catch (Throwable $e) {
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * GET /api/user/addresses/{address}
     *
     * Show a single saved address.
     */
    public function show(SavedAddress $address): JsonResponse
    {
        try {
            $this->assertOwner($address);

            $this->data = $address;
            $this->status_message = 'Address retrieved.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * PUT|PATCH /api/user/addresses/{address}
     *
     * Update a saved address.
     */
    public function update(Request $request, SavedAddress $address): JsonResponse
    {
        try {
            $this->assertOwner($address);

            $validated = $request->validate([
                'label' => ['nullable', 'string', 'max:100'],
                'name' => ['nullable', 'string', 'max:255'],
                'address_line1' => ['nullable', 'string', 'max:500'],
                'address_line2' => ['nullable', 'string', 'max:500'],
                'city_locality' => ['nullable', 'string', 'max:255'],
                'state_province' => ['nullable', 'string', 'max:255'],
                'postal_code' => ['nullable', 'string', 'max:20'],
                'country_code' => ['nullable', 'string', 'max:10'],
                'phone' => ['nullable', 'string', 'max:50'],
                'is_default' => ['nullable', 'boolean'],
            ]);

            $profile = $this->profile();

            if (isset($validated['is_default']) && $validated['is_default']) {
                SavedAddress::where('profile_id', $profile->id)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update($validated);

            $this->syncUserAddress($profile);

            $this->data = $address->fresh();
            $this->status_message = 'Address updated.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * DELETE /api/user/addresses/{address}
     *
     * Delete a saved address.
     */
    public function destroy(SavedAddress $address): JsonResponse
    {
        try {
            $this->assertOwner($address);
            $profile = $this->profile();
            $address->delete();

            $this->syncUserAddress($profile);

            $this->status_message = 'Address deleted.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * PATCH /api/user/addresses/{address}/set-default
     *
     * Mark a saved address as the default.
     */
    public function setDefault(SavedAddress $address): JsonResponse
    {
        try {
            $this->assertOwner($address);
            $profile = $this->profile();

            SavedAddress::where('profile_id', $profile->id)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);

            $address->update(['is_default' => true]);

            $this->syncUserAddress($profile);

            $this->data = $address->fresh();
            $this->status_message = 'Default address updated.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Helpers ────────────────────────────────────────────────────

    protected function profile()
    {
        $user = Auth::guard('jwt')->user();
        if (! $user) {
            throw new \Exception('Authentication required.', 401);
        }
        $profile = $user->profile;
        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return $profile;
    }

    protected function assertOwner(SavedAddress $address): void
    {
        $profile = $this->profile();
        if ((int) $address->profile_id !== (int) $profile->id) {
            throw new \Exception('Address not found.', 404);
        }
    }

    protected function syncUserAddress($profile): void
    {
        $defaultAddress = SavedAddress::where('profile_id', $profile->id)
            ->where('is_default', true)
            ->first();

        if ($profile->user) {
            if ($defaultAddress) {
                $profile->user->update([
                    'address_line_1' => $defaultAddress->address_line1,
                    'address_line_2' => $defaultAddress->address_line2,
                    'city' => $defaultAddress->city_locality,
                    'postal_code' => $defaultAddress->postal_code,
                    'country' => $defaultAddress->country_code,
                ]);
            } else {
                // If there are absolutely zero default addresses left, clear the profile address
                $profile->user->update([
                    'address_line_1' => null,
                    'address_line_2' => null,
                    'city' => null,
                    'postal_code' => null,
                    'country' => null,
                ]);
            }
        }
    }
}
