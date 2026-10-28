<?php

namespace App\Http\Resources;

use App\Models\ShopifyImport;
use App\Models\ShopifyWebhookCall;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopifyStoreHealthResource extends JsonResource
{
    public function toArray($request): array
    {
        // ─── Sync health ─────────────────────────────────────────────
        $lastSyncAt = $this->last_sync_at;
        $syncLagMinutes = $lastSyncAt ? $lastSyncAt->diffInMinutes(now()) : null;

        // ─── Import health (latest import) ───────────────────────────
        $latestImport = ShopifyImport::where('shopify_store_id', $this->id)
            ->latest()
            ->first();

        // ─── Webhook health (last 24h) ──────────────────────────────
        $webhookStats = [
            'total_24h' => ShopifyWebhookCall::where('shopify_store_id', $this->id)
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'failed_24h' => ShopifyWebhookCall::where('shopify_store_id', $this->id)
                ->where('created_at', '>=', now()->subDay())
                ->whereIn('status', ['failed', 'dead_letter'])
                ->count(),
            'dead_lettered' => ShopifyWebhookCall::where('shopify_store_id', $this->id)
                ->where('status', 'dead_letter')
                ->count(),
        ];

        // ─── Token health ────────────────────────────────────────────
        $tokenExpiring = $this->token_expires_at
            ? $this->token_expires_at->isPast() || $this->token_expires_at->diffInDays(now()) <= 7
            : null;

        // ─── Overall health status ───────────────────────────────────
        $issues = [];

        if ($syncLagMinutes !== null && $syncLagMinutes > 60) {
            $issues[] = "Sync lag: {$syncLagMinutes} minutes since last sync";
        }
        if ($tokenExpiring) {
            $issues[] = 'Token is expired or expiring within 7 days';
        }
        if ($webhookStats['failed_24h'] > 10) {
            $issues[] = "High webhook failure rate: {$webhookStats['failed_24h']} failed in 24h";
        }
        if ($webhookStats['dead_lettered'] > 0) {
            $issues[] = "{$webhookStats['dead_lettered']} webhooks in dead letter queue";
        }
        if ($this->status !== 'active') {
            $issues[] = "Store status is '{$this->status}' (not active)";
        }
        if ($this->deleted_at) {
            $issues[] = 'Store is soft-deleted/disconnected';
        }

        $healthStatus = empty($issues) ? 'healthy' : (count($issues) <= 2 ? 'degraded' : 'unhealthy');

        return [
            'store_id' => $this->id,
            'store_domain' => $this->store_domain,
            'shop_name' => $this->shop_name,
            'status' => $this->status,
            'health_status' => $healthStatus,
            'issues' => $issues,
            'issues_count' => count($issues),

            // ── Sync ──────────────────────────────────────────────
            'sync' => [
                'last_sync_at' => $lastSyncAt?->toIso8601String(),
                'sync_lag_minutes' => $syncLagMinutes,
                'is_synced_recently' => $syncLagMinutes !== null && $syncLagMinutes <= 60,
                'currency' => $this->currency,
                'country' => $this->country,
                'timezone' => $this->timezone,
            ],

            // ── Import ────────────────────────────────────────────
            'import' => $latestImport ? [
                'latest_id' => $latestImport->id,
                'latest_status' => $latestImport->status,
                'latest_completed_at' => $latestImport->completed_at?->toIso8601String(),
                'total_imported' => $latestImport->imported_count ?? 0,
                'total_failed' => $latestImport->failed_count ?? 0,
                'total_updated' => $latestImport->updated_count ?? 0,
            ] : null,

            // ── Webhook ───────────────────────────────────────────
            'webhooks' => $webhookStats,

            // ── Token ─────────────────────────────────────────────
            'token' => [
                'expires_at' => $this->token_expires_at?->toIso8601String(),
                'is_expiring_soon' => $tokenExpiring,
                'scopes' => $this->scopes,
            ],

            // ── Policies ──────────────────────────────────────────
            'policies' => [
                'price_sync_policy' => $this->price_sync_policy ?? 'shopify_always',
                'image_sync_strategy' => $this->image_sync_strategy ?? 'always',
                'inventory_sync_enabled' => (bool) $this->inventory_sync_enabled,
            ],

            'installed_at' => $this->installed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
