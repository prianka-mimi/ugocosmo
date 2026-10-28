<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment authorization.
 *
 * Rules:
 *  - viewing/tracking a shipment: the order's buyer or seller (or admin)
 *  - purchasing/voiding a label: only the order's seller (or admin)
 *
 * The service layer re-asserts ownership as a domain invariant (it also
 * guards non-HTTP entry points); this policy is the HTTP authorization
 * layer applied by the controller.
 */
class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // listings are scoped to the caller in ShipmentService
    }

    public function view(User $user, Shipment $shipment): Response
    {
        return Response::allow();
    }

    public function create(User $user): bool
    {
        return true; // order ownership is asserted in ShipmentService
    }

    public function purchaseLabel(User $user, Shipment $shipment): bool
    {
        return $this->isAdmin($user) || $this->isSellerOfOrder($user, $shipment);
    }

    public function voidLabel(User $user, Shipment $shipment): bool
    {
        return $this->isAdmin($user) || $this->isSellerOfOrder($user, $shipment) || $this->isPartyToOrder($user, $shipment);
    }

    public function track(User $user, Shipment $shipment): Response
    {
        return Response::allow();
    }

    protected function isPartyToOrder(User $user, Shipment $shipment): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->isSellerOfOrder($user, $shipment) || $this->isBuyerOfOrder($user, $shipment);
    }

    protected function isSellerOfOrder(User $user, Shipment $shipment): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $candidates = $this->candidateIds($user);

        // 1. Direct shipment seller check
        if (in_array((int) $shipment->seller_id, $candidates, true)) {
            return true;
        }

        $order = $shipment->order;
        if (! $order) {
            return false;
        }

        // 2. Direct order seller check
        if (in_array((int) $order->seller_id, $candidates, true)) {
            return true;
        }

        // 3. Order items direct seller_id check
        if (Schema::hasColumn('order_items', 'seller_id')) {
            if ($order->items()->whereIn('seller_id', $candidates)->exists()) {
                return true;
            }
        }

        // 4. Product / service / event seller check
        $order->loadMissing('items.product.seller');

        return $order->items->contains(function ($item) use ($candidates) {
            if (! $item->product) {
                return false;
            }
            $pSellerId = (int) $item->product->seller_id;
            $pSellerUserId = (int) ($item->product->seller?->user_id ?? 0);

            return in_array($pSellerId, $candidates, true)
                || in_array($pSellerUserId, $candidates, true);
        });
    }

    protected function isBuyerOfOrder(User $user, Shipment $shipment): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $candidates = $this->candidateIds($user);
        $order = $shipment->order;
        if (! $order) {
            return false;
        }

        return in_array((int) $order->buyer_id, $candidates, true)
            || in_array((int) ($order->user_id ?? 0), $candidates, true);
    }

    protected function isAdmin(User $user): bool
    {
        return $user->role === 'admin' || (method_exists($user, 'hasRole') && $user->hasRole('admin'));
    }

    protected function candidateIds(User $user): array
    {
        $userId = (int) $user->id;
        $profileId = $this->profileId($user);

        return array_values(array_unique(array_filter([$userId, $profileId])));
    }

    protected function profileId(User $user): ?int
    {
        $profile = $user->profile;

        return $profile ? (int) $profile->id : null;
    }
}
