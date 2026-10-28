<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterDeviceTokenRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DeviceTokenController
 *
 * Manages FCM device token registration and unregistration.
 * Tokens allow the server to send push notifications to specific devices.
 *
 * ─── Flows ───────────────────────────────────────────────────────────
 * 1. Register  → POST   /api/device-tokens   (save/update FCM token)
 * 2. Unregister → DELETE /api/device-tokens   (remove current token)
 * 3. Unregister → DELETE /api/device-tokens/{id}  (remove specific token)
 */
class DeviceTokenController extends Controller
{
    /**
     * Register or update a device token for push notifications.
     *
     * If the same fcm_token already exists for this user, it re-activates it
     * instead of creating a duplicate (idempotent registration).
     *
     * Request body (x-www-form-urlencoded):
     *   fcm_token   : string (required) — FCM registration token
     *   device_type : string (required) — android | ios | web
     */
    public function register(RegisterDeviceTokenRequest $request): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();

            // Idempotent: updateOrCreate so we don't create duplicates on re-registration
            $token = DeviceToken::updateOrCreate(
                [
                    'user_id' => $profileId,
                    'fcm_token' => $request->input('fcm_token'),
                ],
                [
                    'device_type' => $request->input('device_type', 'android'),
                    'is_active' => true,
                ]
            );

            $this->data = [
                'id' => $token->id,
                'device_type' => $token->device_type,
                'is_active' => $token->is_active,
            ];
            $this->status_message = 'Device token registered successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Device token registration error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Unregister (deactivate) the current user's device token by token value.
     *
     * Send fcm_token in the request body to deactivate that specific token.
     * This is called when the user logs out or the device token changes.
     *
     * Request body (x-www-form-urlencoded):
     *   fcm_token : string (optional) — if omitted, deactivates all tokens
     */
    public function unregister(): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();
            $fcmToken = request()->input('fcm_token');

            $query = DeviceToken::forUser($profileId);

            if ($fcmToken) {
                $query->where('fcm_token', $fcmToken);
            }

            $count = $query->update(['is_active' => false]);

            $this->data = ['deactivated_count' => $count];
            $this->status_message = $count > 0
                ? 'Device token(s) unregistered successfully'
                : 'No matching tokens to unregister';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Device token unregister error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Delete a specific device token by ID.
     */
    public function destroy(DeviceToken $deviceToken): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();

            if ((int) $deviceToken->user_id !== $profileId) {
                abort(404);
            }

            $deviceToken->delete();

            $this->data = [];
            $this->status_message = 'Device token removed successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Device token deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * List all active device tokens for the current user.
     */
    public function index(): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();

            $this->data = DeviceToken::forUser($profileId)
                ->active()
                ->get(['id', 'fcm_token', 'device_type', 'is_active', 'created_at']);

            $this->status_message = 'Device tokens retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Device tokens retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get the authenticated user's profile ID.
     */
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
