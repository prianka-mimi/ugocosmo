<?php

namespace App\Manager;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/**
 * WebPushNotificationService
 *
 * Sends browser push notifications via the Web Push API using VAPID
 * (Voluntary Application Server Identification) protocol.
 *
 * Unlike FCM (which uses Google's proprietary protocol), Web Push is an
 * open standard supported by all major browsers (Chrome, Firefox, Edge,
 * and Safari via Push API).
 *
 * ─── Setup ────────────────────────────────────────────────────────
 * 1. Generate VAPID keys (run once, then save to .env):
 *      php -r "echo json_encode(\Minishlink\WebPush\VAPID::createVapidKeys());"
 *
 * 2. Add to your .env:
 *      VAPID_PUBLIC_KEY=BGxs... (the public key from the JSON above)
 *      VAPID_PRIVATE_KEY=Qf8k... (the private key from the JSON above)
 *      VAPID_SUBJECT=mailto:admin@projectname.com
 *        (or https://yourdomain.com — used as contact info by push services)
 *
 * 3. The front-end JavaScript uses the PUBLIC key to subscribe:
 *      navigator.serviceWorker.ready.then(reg => {
 *          reg.pushManager.subscribe({
 *              userVisibleOnly: true,
 *              applicationServerKey: vapidPublicKey
 *          });
 *      });
 */
class WebPushNotificationService
{
    protected ?WebPush $webPush = null;

    /**
     * Send a Web Push notification to all browser subscriptions of a user.
     *
     * @param  int  $userId  Profile ID of the recipient.
     * @param  string  $title  Notification title.
     * @param  string  $body  Notification body text.
     * @param  array  $data  Optional data payload (e.g. IDs, URLs for deep linking).
     * @param  string  $icon  Optional icon URL for the notification.
     * @param  string  $badge  Optional badge URL for the notification.
     * @return int Number of successful pushes.
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = [], string $icon = '', string $badge = ''): int
    {
        $subscriptions = PushSubscription::forUser($userId)->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $webPush = $this->getWebPush();
        if (! $webPush) {
            return 0;
        }

        // Build notification payload as JSON
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => $icon ?: config('app.url').'/favicon.ico',
            'badge' => $badge ?: config('app.url').'/badge.png',
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE);

        $successCount = 0;

        foreach ($subscriptions as $sub) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'authToken' => $sub->auth,      // auth secret from browser
                        'publicKey' => $sub->p256dh,     // client public key
                        'contentEncoding' => 'aes128gcm', // standard encryption
                    ]),
                    $payload,
                    ['TTL' => 86400] // 24 hours TTL
                );
            } catch (\Throwable $e) {
                Log::warning('WebPushNotificationService: Failed to queue notification', [
                    'subscription_id' => $sub->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Flush all queued notifications
        $results = $webPush->flush();

        if ($results === false) {
            return 0;
        }

        // Process results — remove expired/invalid subscriptions
        foreach ($results as $result) {
            if ($result->isSuccess()) {
                $successCount++;
            } else {
                // Subscription expired or invalid — remove it
                $endpoint = $result->getEndpoint();
                Log::info('WebPushNotificationService: Removing expired subscription', [
                    'endpoint' => $endpoint,
                    'reason' => $result->getReason(),
                ]);

                PushSubscription::byEndpoint($endpoint)->delete();
            }
        }

        return $successCount;
    }

    /**
     * Get or create the WebPush instance with VAPID auth.
     */
    protected function getWebPush(): ?WebPush
    {
        if ($this->webPush !== null) {
            return $this->webPush;
        }

        $publicKey = config('services.vapid.public_key');
        $privateKey = config('services.vapid.private_key');
        $subject = config('services.vapid.subject');

        if (! $publicKey || ! $privateKey || ! $subject) {
            Log::warning('WebPushNotificationService: VAPID keys not configured. Set VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, and VAPID_SUBJECT in .env');

            return null;
        }

        try {
            $auth = [
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ];

            // Default TTL of 4 weeks (max Chrome allows)
            $defaultOptions = [
                'TTL' => 86400 * 28,
                'batchSize' => 200,
            ];

            $this->webPush = new WebPush($auth, $defaultOptions, 30);

            return $this->webPush;

        } catch (\Throwable $e) {
            Log::error('WebPushNotificationService: Failed to initialize WebPush: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Generate new VAPID keys (for setup / CLI command).
     *
     * Usage: php -r "print_r(\App\Manager\WebPushNotificationService::generateVapidKeys());"
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generateVapidKeys(): array
    {
        return VAPID::createVapidKeys();
    }
}
