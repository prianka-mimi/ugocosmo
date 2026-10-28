<?php

namespace App\Console\Commands;

use App\Exceptions\ShipEngine\ShipEngineValidationException;
use App\Services\ShipEngineApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Register (or confirm) the ShipEngine tracking webhook with the provider.
 *
 * Uses the official POST /v1/environment/webhooks endpoint. Only one URL
 * may be configured per event — a 409 Conflict means the URL is already
 * registered, which we treat as success. Never overwrites or deletes
 * unrelated webhooks.
 *
 * Usage:
 *   php artisan shipengine:register-webhook
 *
 * Environment: APP_URL must be a publicly reachable HTTPS URL in production.
 */
class RegisterShipEngineWebhook extends Command
{
    protected $signature = 'shipengine:register-webhook';

    protected $description = 'Register the tracking webhook URL with ShipEngine';

    public function handle(ShipEngineApiClient $client): int
    {
        $webhookUrl = config('app.url').'/api/webhooks/shipengine/tracking';
        $event = 'track';

        $this->info("Checking existing webhooks for URL: {$webhookUrl}");

        try {
            $existing = $client->listWebhooks();
        } catch (\Throwable $e) {
            $this->error('Failed to list webhooks: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($existing['webhooks'] ?? [] as $webhook) {
            $configuredUrl = (string) ($webhook['url'] ?? '');
            $configuredEvent = (string) ($webhook['event'] ?? '');

            if ($configuredUrl === $webhookUrl && $configuredEvent === $event) {
                $this->info('Webhook already registered for the tracking event.');
                $this->table(
                    ['Webhook ID', 'URL', 'Event', 'Disabled'],
                    [[
                        $webhook['id'] ?? '?',
                        $configuredUrl,
                        $configuredEvent,
                        $webhook['disabled'] ?? false ? 'true' : 'false',
                    ]]
                );

                return self::SUCCESS;
            }
        }

        $this->info("Registering new webhook for event '{$event}'...");

        $payload = ['url' => $webhookUrl, 'event' => $event];

        try {
            $response = $client->createWebhook($webhookUrl, $event);

            $this->info('Webhook registered successfully.');
            $this->table(
                ['Webhook ID', 'URL', 'Event'],
                [[
                    $response['webhook_id'] ?? $response['id'] ?? '?',
                    $payload['url'],
                    $payload['event'],
                ]]
            );

            Log::channel('shipengine')->info('ShipEngine webhook registered', [
                'webhook_id' => $response['webhook_id'] ?? $response['id'] ?? null,
                'url' => $webhookUrl,
                'event' => $event,
            ]);

            return self::SUCCESS;
        } catch (ShipEngineValidationException $e) {
            // 409 Conflict: URL already registered for this event.
            $body = $e->payload ?? null;
            if (is_array($body) && isset($body['error_code']) && $body['error_code'] === 'webhook_conflict') {
                $this->warn('Webhook URL is already registered for this event (409 Conflict). This is expected.');

                return self::SUCCESS;
            }

            $this->error('Webhook registration failed (validation): '.$e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Webhook registration failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
