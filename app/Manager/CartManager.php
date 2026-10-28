<?php

namespace App\Manager;

use App\Models\Booking;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\EventTicket;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class CartManager
{
    public function show(): Cart
    {
        $cart = $this->getOrCreateCart();

        return $cart->load('items.product');
    }

    /**
     * Add an item to the cart.
     *
     * API format (product-only):
     *   ['product_id' => int, 'quantity' => int]
     *
     * Legacy internal format (service/ticket):
     *   ['item_type' => 'service'|'ticket', 'item_id' => int, 'quantity' => int, ...]
     */
    public function add(array $data): CartItem
    {
        $cart = $this->getOrCreateCart();

        // ── Product (new API format) ──────────────────────────────
        if (isset($data['product_id'])) {
            $product = Product::active()->findOrFail($data['product_id']);

            if ((int) $product->seller_id === (int) $this->profileId()) {
                throw new \Exception('You cannot purchase your own product.', 422);
            }

            $requestedQty = max(1, (int) ($data['quantity'] ?? 1));

            // Inventory validation: reject if requested quantity exceeds available stock
            if ($product->stock_quantity !== null && (int) $product->stock_quantity < $requestedQty) {
                throw new \Exception("Product '{$product->title}' has insufficient stock ({$product->stock_quantity} available, requested {$requestedQty}).", 422);
            }

            $existing = $cart->items()
                ->where('product_id', $product->id)
                ->first();

            if ($existing) {
                $newQty = $existing->quantity + $requestedQty;
                if ($product->stock_quantity !== null && (int) $product->stock_quantity < $newQty) {
                    throw new \Exception("Product '{$product->title}' has insufficient stock ({$product->stock_quantity} available, total requested in cart {$newQty}).", 422);
                }

                $existing->increment('quantity', $requestedQty);

                return $existing->fresh();
            }

            return $cart->items()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'title' => $product->title,
                'image_url' => $product->image_url,
                'unit_price' => $product->price,
                'quantity' => $requestedQty,
                'item_type' => 'product',
            ]);
        }

        // ── Service (internal — called from BookingManager) ───────
        if (($data['item_type'] ?? null) === 'service') {
            $service = Service::active()->findOrFail($data['item_id']);

            if ((int) $service->provider_id === (int) $this->profileId()) {
                throw new \Exception('You cannot book your own service.', 422);
            }

            $bookingId = $data['booking_id'] ?? null;
            $unitPrice = $service->price;
            $title = $service->title;

            if ($bookingId) {
                $booking = Booking::findOrFail($bookingId);
                $unitPrice = $booking->subtotal;
                if ($booking->package_id && $booking->package) {
                    $title .= ' - '.$booking->package->title;
                }
            }

            $existing = $cart->items()
                ->where('service_id', $service->id)
                ->where('booking_id', $bookingId)
                ->first();

            if ($existing) {
                $existing->increment('quantity', $data['quantity'] ?? 1);

                return $existing->fresh();
            }

            return $cart->items()->create([
                'cart_id' => $cart->id,
                'service_id' => $service->id,
                'booking_id' => $bookingId,
                'title' => $title,
                'image_url' => $service->image_url,
                'unit_price' => $unitPrice,
                'quantity' => $data['quantity'] ?? 1,
                'item_type' => 'service',
            ]);
        }

        // ── Ticket (internal — called from TicketManager) ──────────
        if (($data['item_type'] ?? null) === 'ticket') {
            $ticket = EventTicket::findOrFail($data['item_id']);
            $event = $ticket->event;

            if ($event && (int) $event->organizer_id === (int) $this->profileId()) {
                throw new \Exception('You cannot purchase tickets for your own event.', 422);
            }

            $existing = $cart->items()
                ->where('item_type', 'ticket')
                ->where('event_id', $event?->id)
                ->first();

            if ($existing) {
                $ticket->update(['cart_item_id' => $existing->id]);
                $existing->increment('quantity', $data['quantity'] ?? 1);

                return $existing->fresh();
            }

            $cartItem = $cart->items()->create([
                'cart_id' => $cart->id,
                'event_id' => $event?->id,
                'title' => ($event?->title ?? 'Event').' Tickets',
                'image_url' => $event?->image_url,
                'unit_price' => $ticket->seat?->price ?? $ticket->section?->price ?? $event?->price ?? 0,
                'quantity' => $data['quantity'] ?? 1,
                'item_type' => 'ticket',
            ]);

            $ticket->update(['cart_item_id' => $cartItem->id]);

            return $cartItem;
        }

        throw new \Exception('Invalid request. Provide product_id for products.', 422);
    }

    public function updateQuantity(CartItem $item, int $quantity): CartItem
    {
        $this->ensureOwned($item);

        if ($quantity <= 0) {
            $item->delete();
            throw new \Exception('Item removed from cart', 422);
        }

        $item->update(['quantity' => $quantity]);

        return $item->fresh();
    }

    public function remove(CartItem $item): void
    {
        $this->ensureOwned($item);
        $item->delete();
    }

    public function clear(): void
    {
        $cart = $this->getOrCreateCart();
        $cart->items()->delete();
        $this->clearSelectedShipping();
    }

    /**
     * Store buyer's selected shipping rate for the cart before checkout.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function selectShipping(array $data): array
    {
        $cart = $this->getOrCreateCart();
        $profileId = (int) $this->profileId();

        $shippingData = [
            'rate_id' => $data['rate_id'] ?? null,
            'shipping_method' => $data['shipping_method'] ?? null,
            'carrier_id' => $data['carrier_id'] ?? null,
            'carrier_code' => $data['carrier_code'] ?? null,
            'service_code' => $data['service_code'] ?? null,
            'service_name' => $data['service_name'] ?? null,
            'shipping_amount' => round((float) ($data['shipping_amount'] ?? 0), 2),
            'currency' => strtoupper((string) ($data['currency'] ?? 'USD')),
            'selected_rate_snapshot' => $data['selected_rate_snapshot'] ?? null,
            'selected_at' => now()->toIso8601String(),
        ];

        Cache::put("cart:shipping:{$cart->id}", $shippingData, now()->addDays(3));
        Cache::put("cart:shipping:user:{$profileId}", $shippingData, now()->addDays(3));

        return $shippingData;
    }

    /**
     * Retrieve the buyer's previously selected shipping rate for the cart.
     *
     * @return array<string, mixed>|null
     */
    public function getSelectedShipping(): ?array
    {
        $cart = $this->getOrCreateCart();
        $profileId = (int) $this->profileId();

        return Cache::get("cart:shipping:{$cart->id}")
            ?? Cache::get("cart:shipping:user:{$profileId}");
    }

    /**
     * Clear cached shipping rate selection for the cart.
     */
    public function clearSelectedShipping(): void
    {
        $cart = $this->getOrCreateCart();
        $profileId = (int) $this->profileId();

        Cache::forget("cart:shipping:{$cart->id}");
        Cache::forget("cart:shipping:user:{$profileId}");
    }

    /**
     * Comprehensive cart financial and shipping summary for buyer checkout page.
     * ZERO BUYER FEES: Subtotal + AliExpress Shipping + Selected ShipEngine Rate.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $cart = $this->show();
        $items = $cart->items;

        $subtotal = 0.00;
        $aliexpressShipping = 0.00;
        $itemsBreakdown = [];

        foreach ($items as $item) {
            $product = $item->product;
            $itemSubtotal = round((float) $item->unit_price * $item->quantity, 2);
            $subtotal += $itemSubtotal;

            $isAliExpress = false;
            $itemShippingCost = 0.00;

            if ($product) {
                $isAliExpress = strtolower((string) $product->source) === 'aliexpress'
                    || ! empty($product->aliexpress_product_id);

                if ($isAliExpress) {
                    $itemShippingCost = (float) $product->aliexpress_shipping_cost;
                    $aliexpressShipping += $itemShippingCost;
                }
            }

            $itemsBreakdown[] = [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'title' => $item->title,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'subtotal' => $itemSubtotal,
                'is_aliexpress' => $isAliExpress,
                'aliexpress_shipping_cost' => $isAliExpress ? $itemShippingCost : 0.00,
                'estimated_delivery' => $product?->estimated_delivery_time,
            ];
        }

        $selectedShipping = $this->getSelectedShipping();
        $localShipping = $selectedShipping !== null ? (float) ($selectedShipping['shipping_amount'] ?? 0) : 0.00;

        $totalShipping = round($aliexpressShipping + $localShipping, 2);
        $totalPayable = round($subtotal + $totalShipping, 2);

        return [
            'items_count' => (int) $items->sum('quantity'),
            'subtotal' => round($subtotal, 2),
            'aliexpress_shipping' => round($aliexpressShipping, 2),
            'local_shipping' => round($localShipping, 2),
            'shipping_total' => $totalShipping,
            'buyer_fee' => 0.00,
            'buyer_fee_rate' => '0%',
            'platform_fee' => 0.00,
            'platform_fee_rate' => '0%',
            'seller_commission_rate' => '2.5%',
            'total' => $totalPayable,
            'currency' => 'USD',
            'selected_shipping' => $selectedShipping,
            'items' => $itemsBreakdown,
        ];
    }

    public function count(): int
    {
        $cart = $this->getOrCreateCart();

        return (int) $cart->items()->sum('quantity');
    }

    protected function getOrCreateCart(): Cart
    {
        $profileId = (int) $this->profileId();

        return Cart::firstOrCreate(
            ['user_id' => $profileId]
        );
    }

    protected function ensureOwned(CartItem $item): void
    {
        $cart = $item->cart;
        if ((int) $cart->user_id !== (int) $this->profileId()) {
            abort(404);
        }
    }

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }
}
