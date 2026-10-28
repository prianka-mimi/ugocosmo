<?php

namespace App\Policies;

use App\Models\ShopifyStore;
use App\Models\User;

class ShopifyStorePolicy
{
    /**
     * Determine if the user can view the store.
     */
    public function view(User $user, ShopifyStore $store): bool
    {
        return (int) $store->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can import products from the store.
     */
    public function import(User $user, ShopifyStore $store): bool
    {
        return (int) $store->user_id === (int) $user->id && $store->status === 'active';
    }

    /**
     * Determine if the user can disconnect the store.
     */
    public function disconnect(User $user, ShopifyStore $store): bool
    {
        return (int) $store->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can view products from the store.
     */
    public function viewProducts(User $user, ShopifyStore $store): bool
    {
        return (int) $store->user_id === (int) $user->id && $store->status === 'active';
    }

    /**
     * Determine if the user can sync the store.
     */
    public function sync(User $user, ShopifyStore $store): bool
    {
        return (int) $store->user_id === (int) $user->id && $store->status === 'active';
    }
}
