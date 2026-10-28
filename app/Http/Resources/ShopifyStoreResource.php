<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShopifyStoreResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'user_id' => $this->user_id,
            'shop_name' => $this->shop_name,
            'store_domain' => $this->store_domain,
            'status' => $this->status,
            'currency' => $this->currency,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'scopes' => $this->scopes,
            'installed_at' => $this->installed_at?->toIso8601String(),
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            // ── Sync Policies ────────────────────────────────────────
            'price_sync_policy' => $this->price_sync_policy ?? 'shopify_always',
            'image_sync_strategy' => $this->image_sync_strategy ?? 'always',
            'inventory_sync_enabled' => (bool) $this->inventory_sync_enabled,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
