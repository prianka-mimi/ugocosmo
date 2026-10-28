<?php

namespace App\Manager;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PushNotificationService
 *
 * Sends push notifications via Firebase Cloud Messaging (FCM) HTTP v1 API.
 *
 * ─── Setup ────────────────────────────────────────────────────────
 * 1. Create a Firebase project and enable Cloud Messaging.
 * 2. Generate a service-account JSON key from Firebase Console.
 * 3. Set env vars in your .env:
 *      FCM_CREDENTIALS_PATH=storage/app/firebase-service-account.json
 *      FCM_PROJECT_ID=your-firebase-project-id
 *
 * The service will auto-generate OAuth2 tokens from the service account.
 * On failure (token invalid/unregistered), it marks the device token as inactive.
 */
class PushNotificationService
{
    /**
     * Send a push notification to all active tokens of a specific user.
     *
     * @param  int  $userId  Profile ID of the recipient.
     * @param  string  $title  Notification title.
     * @param  string  $body  Notification body text.
     * @param  array  $data  Optional custom data payload (key-value pairs).
     * @param  string  $channelId  Android notification channel ID (default: 'default').
     * @return int Number of successful pushes sent.
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = [], string $channelId = 'default'): int
    {
        $tokens = DeviceToken::forUser($userId)->active()->get();

        if ($tokens->isEmpty()) {
            return 0;
        }

        $successCount = 0;

        foreach ($tokens as $token) {
            $sent = $this->sendToToken($token, $title, $body, $data, $channelId);
            if ($sent) {
                $successCount++;
            }
        }

        return $successCount;
    }

    /**
     * Send a push notification to a single device token.
     *
     * @param  DeviceToken  $token  The device token model instance.
     * @param  string  $title  Notification title.
     * @param  string  $body  Notification body text.
     * @param  array  $data  Optional custom data payload.
     * @param  string  $channelId  Android notification channel ID.
     * @return bool True if the push was accepted by FCM.
     */
    public function sendToToken(DeviceToken $token, string $title, string $body, array $data = [], string $channelId = 'default'): bool
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::warning('PushNotificationService: No FCM access token available, skipping push');

            return false;
        }

        $message = $this->buildMessage($token, $title, $body, $data, $channelId);

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->getProjectId()}/messages:send", [
                    'message' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            // Handle token errors — deactivate stale tokens
            $this->handleError($token, $response->status(), $response->body());

            return false;

        } catch (\Throwable $e) {
            Log::error('PushNotificationService: FCM request failed', [
                'error' => $e->getMessage(),
                'token_id' => $token->id,
            ]);

            return false;
        }
    }

    /**
     * Send the same push to multiple users in batch.
     *
     * @param  array  $userIds  Array of profile IDs.
     * @param  string  $title  Notification title.
     * @param  string  $body  Notification body text.
     * @param  array  $data  Optional custom data payload.
     * @param  string  $channelId  Android notification channel ID.
     * @return array{total: int, successful: int}
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = [], string $channelId = 'default'): array
    {
        $total = 0;
        $successful = 0;

        foreach ($userIds as $userId) {
            $sent = $this->sendToUser((int) $userId, $title, $body, $data, $channelId);
            $total += $sent > 0 ? 1 : 0;
            $successful += $sent;
        }

        return [
            'total' => $total,
            'successful' => $successful,
        ];
    }

    // ─── Private Helpers ───────────────────────────────────────────

    /**
     * Build the FCM v1 message payload for the given token and notification.
     */
    private function buildMessage(DeviceToken $token, string $title, string $body, array $data, string $channelId): array
    {
        $message = [
            'token' => $token->fcm_token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'android' => [
                'notification' => [
                    'channel_id' => $channelId,
                    'priority' => 'high',
                ],
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'alert' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'sound' => 'default',
                        'badge' => 1,
                    ],
                ],
            ],
        ];

        // Add custom data payload if provided
        if (! empty($data)) {
            $message['data'] = $data;
        }

        return $message;
    }

    /**
     * Handle FCM API errors — deactivate tokens that are no longer valid.
     */
    private function handleError(DeviceToken $token, int $httpStatus, string $responseBody): void
    {
        // FCM returns 404 for unregistered tokens, 400 for invalid tokens
        $shouldDeactivate = in_array($httpStatus, [400, 404]);

        if ($shouldDeactivate) {
            Log::info('PushNotificationService: Deactivating stale device token', [
                'token_id' => $token->id,
                'http_status' => $httpStatus,
                'response' => $responseBody,
            ]);

            $token->update(['is_active' => false]);
        } else {
            Log::warning('PushNotificationService: FCM push failed', [
                'token_id' => $token->id,
                'http_status' => $httpStatus,
                'response' => $responseBody,
            ]);
        }
    }

    /**
     * Get an OAuth2 access token using the Firebase service account JSON.
     *
     * Uses a simple file-based cache to avoid fetching a new token on every push.
     * The token expires after 3600 seconds (1 hour).
     */
    private function getAccessToken(): ?string
    {
        $credentialsPath = config('services.firebase.credentials_path', storage_path('app/firebase-service-account.json'));

        if (! file_exists($credentialsPath)) {
            Log::warning('PushNotificationService: Firebase credentials file not found at: '.$credentialsPath);

            return null;
        }

        // Check cached token
        $cacheKey = 'fcm_access_token';
        $cachedToken = cache()->get($cacheKey);

        if ($cachedToken) {
            return $cachedToken;
        }

        try {
            $serviceAccount = json_decode(file_get_contents($credentialsPath), true, 512, JSON_THROW_ON_ERROR);

            $clientEmail = $serviceAccount['client_email'] ?? null;
            $privateKey = $serviceAccount['private_key'] ?? null;

            if (! $clientEmail || ! $privateKey) {
                Log::error('PushNotificationService: Invalid service account JSON — missing client_email or private_key');

                return null;
            }

            // Create JWT assertion for OAuth2 token exchange
            $now = time();
            $jwtHeader = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $jwtPayload = $this->base64UrlEncode(json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]));

            // Sign the JWT with the private key
            $signature = '';
            openssl_sign("{$jwtHeader}.{$jwtPayload}", $signature, $privateKey, 'sha256WithRSAEncryption');
            $jwt = "{$jwtHeader}.{$jwtPayload}.".$this->base64UrlEncode($signature);

            // Exchange JWT for access token
            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                Log::error('PushNotificationService: Failed to exchange JWT for access token', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $accessToken = $response->json('access_token');
            $expiresIn = $response->json('expires_in', 3600);

            // Cache the token for 55 minutes (to be safe)
            cache()->put($cacheKey, $accessToken, max($expiresIn - 300, 60));

            return $accessToken;

        } catch (\Throwable $e) {
            Log::error('PushNotificationService: Error getting FCM access token: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Get the Firebase project ID from config.
     */
    private function getProjectId(): string
    {
        return config('services.firebase.project_id', '');
    }

    /**
     * Base64 URL-safe encode (no padding, replace +/ with -_).
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
