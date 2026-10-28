<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EtsyAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        // NEVER expose access_token / refresh_token — even encrypted.
        return [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'etsy_user_id' => $this->etsy_user_id,
            'shop_id' => $this->shop_id,
            'shop_name' => $this->shop_name,
            'account_name' => $this->account_name,
            'status' => $this->status,
            'scopes' => $this->scopes,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'refresh_token_valid_until' => $this->refresh_token_valid_until?->toIso8601String(),
            'is_token_expired' => $this->isTokenExpired(),
            'is_refresh_token_expired' => $this->isRefreshTokenExpired(),
            'needs_reconnect' => $this->isTokenExpired() && $this->isRefreshTokenExpired(),
            'last_error' => $this->last_error,
            'stats' => [
                'imported_products' => $this->whenCounted('products', fn () => (int) $this->products_count),
                'total_imports' => $this->whenCounted('imports', fn () => (int) $this->imports_count),
            ],
        ];
    }
}
