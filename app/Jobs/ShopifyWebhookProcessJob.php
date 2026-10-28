<?php

namespace App\Jobs;

use App\Manager\ShopifyWebhookManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 5; // Matches dead-letter max attempts

    public int $backoff = 10;

    public int $maxExceptions = 5;

    public function __construct(
        protected string $topic,
        protected string $shopDomain,
        protected array $payload,
        protected ?int $webhookCallId = null,
        protected ?string $webhookId = null,
    ) {}

    public function handle(ShopifyWebhookManager $webhookManager): void
    {
        try {
            $result = $webhookManager->processWebhook(
                topic: $this->topic,
                shopDomain: $this->shopDomain,
                payload: $this->payload,
                webhookId: $this->webhookId,
                webhookCallId: $this->webhookCallId,
            );

            Log::info("Shopify webhook processed: {$this->topic}", [
                'shop' => $this->shopDomain,
                'webhook_id' => $this->webhookId,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error("Shopify webhook processing failed: {$this->topic}", [
                'shop' => $this->shopDomain,
                'webhook_id' => $this->webhookId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw for retry (will stop at maxExceptions)
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Shopify webhook job permanently failed: {$this->topic}", [
            'shop' => $this->shopDomain,
            'webhook_id' => $this->webhookId,
            'webhook_call_id' => $this->webhookCallId,
            'error' => $exception->getMessage(),
        ]);
    }
}
