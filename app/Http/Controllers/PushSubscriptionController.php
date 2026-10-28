<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscribePushRequest;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PushSubscriptionController
 *
 * Manages browser push notification subscriptions (Web Push API / VAPID).
 * Stores the endpoint, p256dh, and auth keys that browsers provide when a
 * user subscribes to push notifications.
 *
 * Endpoints:
 *   POST   /api/push-subscriptions         → Subscribe this browser
 *   DELETE /api/push-subscriptions/{id}     → Unsubscribe a specific subscription
 *   DELETE /api/push-subscriptions          → Unsubscribe by endpoint (body param)
 *   GET    /api/push-subscriptions          → List all subscriptions for current user
 */
class PushSubscriptionController extends Controller
{
    /**
     * List all browser push subscriptions for the current user.
     */
    public function index(): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();

            $this->data = PushSubscription::forUser($profileId)
                ->get(['id', 'endpoint', 'created_at']);

            $this->status_message = 'Push subscriptions retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Push subscriptions retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Subscribe the current browser to push notifications.
     *
     * The front-end should call this after the browser's Push API
     * successfully subscribes. Stores the subscription data so the
     * server can send push notifications later.
     *
     * Request body (x-www-form-urlencoded):
     *   endpoint : string (required) — Push API endpoint URL
     *   p256dh   : string (required) — Client public ECDH key (base64)
     *   auth     : string (required) — Client auth secret (base64)
     *
     * Idempotent: If the same endpoint already exists, updates the keys
     * (in case they changed) instead of creating a duplicate.
     */
    public function subscribe(SubscribePushRequest $request): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();

            // Idempotent — updateOrCreate by endpoint URL
            $subscription = PushSubscription::updateOrCreate(
                [
                    'user_id' => $profileId,
                    'endpoint' => $request->input('endpoint'),
                ],
                [
                    'p256dh' => $request->input('p256dh'),
                    'auth' => $request->input('auth'),
                ]
            );

            $this->data = [
                'id' => $subscription->id,
                'endpoint' => $subscription->endpoint,
            ];
            $this->status_message = 'Push subscription created successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            Log::error('Push subscription create error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Unsubscribe (delete) a specific push subscription by ID.
     */
    public function destroy(PushSubscription $pushSubscription): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();

            if ((int) $pushSubscription->user_id !== $profileId) {
                abort(404);
            }

            $pushSubscription->delete();

            $this->data = [];
            $this->status_message = 'Push subscription removed successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Push subscription delete error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Unsubscribe by endpoint URL (useful when the browser changes its endpoint).
     *
     * Request body (x-www-form-urlencoded):
     *   endpoint : string (required) — The endpoint URL to remove
     */
    public function unsubscribeByEndpoint(): JsonResponse
    {
        try {
            $profileId = (int) $this->profileId();
            $endpoint = request()->input('endpoint');

            if (! $endpoint) {
                throw new \Exception('The endpoint field is required', 422);
            }

            $count = PushSubscription::forUser($profileId)
                ->byEndpoint($endpoint)
                ->delete();

            $this->data = ['deleted_count' => $count];
            $this->status_message = $count > 0
                ? 'Push subscription removed'
                : 'No matching subscription found';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Push subscription delete by endpoint error: '.$e->getMessage());
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
