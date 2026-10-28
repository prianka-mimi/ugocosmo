<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ShopifyStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;

class ShopifyStoreRepository
{
    /**
     * Find a store by ID with ownership check.
     */
    public function findById(int $id, int $userId): ?ShopifyStore
    {
        return ShopifyStore::where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Find a store by ID without ownership check (internal use).
     */
    public function findByIdRaw(int $id): ?ShopifyStore
    {
        return ShopifyStore::whereNull('deleted_at')->find($id);
    }

    /**
     * Find a store by domain for a specific user.
     */
    public function findByDomain(int $userId, string $domain): ?ShopifyStore
    {
        return ShopifyStore::where('user_id', $userId)
            ->where('store_domain', $domain)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Get all active stores for a user.
     */
    public function getByUser(int $userId): Collection
    {
        return ShopifyStore::where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->get();
    }

    /**
     * Get all active stores (for sync command).
     */
    public function getAllActive(): Collection
    {
        return ShopifyStore::where('status', 'active')
            ->whereNull('deleted_at')
            ->get();
    }

    /**
     * Create a new store connection.
     */
    public function create(array $data): ShopifyStore
    {
        if (isset($data['access_token'])) {
            $data['access_token'] = Crypt::encryptString($data['access_token']);
        }

        return ShopifyStore::create($data);
    }

    /**
     * Update an existing store connection.
     */
    public function update(ShopifyStore $store, array $data): ShopifyStore
    {
        if (isset($data['access_token'])) {
            $data['access_token'] = Crypt::encryptString($data['access_token']);
        }

        $store->update($data);

        return $store->fresh();
    }

    /**
     * Soft-disconnect a store (keeps imported products).
     */
    public function softDisconnect(ShopifyStore $store): void
    {
        $store->update([
            'status' => 'disconnected',
            'deleted_at' => now(),
        ]);
    }

    /**
     * Hard-delete a store and optionally its imported products.
     *
     * NOTE: The importedProducts() relationship is not defined on ShopifyStore.
     * When $deleteProducts is true, products are looked up via source + shopify_store_id.
     */
    public function hardDelete(ShopifyStore $store, bool $deleteProducts = false): void
    {
        if ($deleteProducts) {
            Product::where('source', 'shopify')
                ->where('shopify_store_id', $store->id)
                ->delete();
        }
        $store->forceDelete();
    }

    /**
     * Decrypt the access token for API calls.
     */
    public function decryptToken(ShopifyStore $store): string
    {
        try {
            return Crypt::decryptString($store->access_token);
        } catch (\Exception $e) {
            return $store->access_token; // fallback if not encrypted
        }
    }

    /**
     * Check if a user already has this store connected.
     */
    public function existsForUser(int $userId, string $domain): bool
    {
        return ShopifyStore::where('user_id', $userId)
            ->where('store_domain', $domain)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Count total stores for a user.
     */
    public function countByUser(int $userId): int
    {
        return ShopifyStore::where('user_id', $userId)
            ->whereNull('deleted_at')
            ->count();
    }
}
