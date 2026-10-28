<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NotificationPreferenceController
 *
 * Allows users to view and update their notification preferences.
 * Each user has a single preference row that controls whether push (FCM)
 * and in-app (DB) notifications are delivered for each category.
 *
 * Endpoints:
 *   GET  /api/notification-preferences → show current preferences
 *   PUT  /api/notification-preferences → update preferences
 */
class NotificationPreferenceController extends Controller
{
    /**
     * Get the current user's notification preferences.
     *
     * If the user has never set preferences, defaults are auto-created:
     *   { "booking": {"push":true,"in_app":true}, "ticket": ... }
     *
     * Response:
     *   {
     *     "status": true,
     *     "data": {
     *       "booking":    { "push": true, "in_app": true },
     *       "ticket":     { "push": true, "in_app": true },
     *       "message":    { "push": true, "in_app": true },
     *       "livestream": { "push": true, "in_app": true }
     *     },
     *     "message": "Notification preferences retrieved successfully"
     *   }
     */
    public function show(): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();

            $prefs = NotificationPreference::forUser($profileId);

            $this->data = $prefs->preferences ?? NotificationPreference::DEFAULTS;
            $this->status_message = 'Notification preferences retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Notification preferences retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Update the current user's notification preferences.
     *
     * Accepts form fields with dot-notation keys (preferred for x-www-form-urlencoded):
     *   booking.push   = 1
     *   booking.in_app = 0
     *
     * Or a JSON body with nested objects.
     *
     * Only the categories sent in the request are updated — omitted categories
     * keep their existing values (partial update).
     *
     * Request example (x-www-form-urlencoded):
     *   booking.push   = 0
     *   booking.in_app = 1
     *   message.push   = 0
     *
     * Response:
     *   {
     *     "status": true,
     *     "data": {
     *       "booking":    { "push": false, "in_app": true },
     *       "ticket":     { "push": true,  "in_app": true },
     *       "message":    { "push": false, "in_app": true },
     *       "livestream": { "push": true,  "in_app": true }
     *     },
     *     "message": "Notification preferences updated successfully"
     *   }
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();

            // Get or create the preference record
            $prefs = NotificationPreference::forUser($profileId);

            // Merge existing preferences with only the categories sent in the request
            $current = $prefs->preferences ?? NotificationPreference::DEFAULTS;
            $requestData = $request->validated();

            $merged = $current;
            foreach ($requestData as $category => $settings) {
                $merged[$category] = [
                    'push' => filter_var($settings['push'] ?? ($current[$category]['push'] ?? true), FILTER_VALIDATE_BOOLEAN),
                    'in_app' => filter_var($settings['in_app'] ?? ($current[$category]['in_app'] ?? true), FILTER_VALIDATE_BOOLEAN),
                    'email' => filter_var($settings['email'] ?? ($current[$category]['email'] ?? true), FILTER_VALIDATE_BOOLEAN),
                ];
            }

            $prefs->update(['preferences' => $merged]);

            $this->data = $merged;
            $this->status_message = 'Notification preferences updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Notification preferences update error: '.$e->getMessage());
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
