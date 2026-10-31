<?php

namespace App\Manager;

use App\DTOs\ShippingOptionDTO;
use App\Models\Booking;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Service;
use App\Models\Transaction;
use App\Services\Fulfillment\FulfillmentProviderResolver;
use App\Services\OrderTimelineService;
use App\Services\StripePaymentIntentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CheckoutManager
{
    protected CartManager $cartManager;

    protected NotificationManager $notificationManager;

    protected OrderTimelineService $timeline;

    protected StripePaymentIntentService $stripePaymentService;

    protected FulfillmentProviderResolver $fulfillmentResolver;

    public function __construct(
        CartManager $cartManager,
        NotificationManager $notificationManager,
        OrderTimelineService $timeline,
        StripePaymentIntentService $stripePaymentService,
        FulfillmentProviderResolver $fulfillmentResolver
    ) {
        $this->cartManager = $cartManager;
        $this->notificationManager = $notificationManager;
        $this->timeline = $timeline;
        $this->stripePaymentService = $stripePaymentService;
        $this->fulfillmentResolver = $fulfillmentResolver;
    }

    public function checkout(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $cart = $this->cartManager->show();

            if ($cart->items->isEmpty() && empty($data['product_id'])) {
                throw new \Exception('Cart is empty. Add items before checkout.', 422);
            }

            // Eager-load product/service/event relationships to avoid N+1 queries
            $cart->load('items.product', 'items.service', 'items.event');

            $cartItems = $cart->items;
            if (isset($data['cart_item_ids']) && is_array($data['cart_item_ids']) && ! empty($data['cart_item_ids'])) {
                $cartItems = $cartItems->whereIn('id', $data['cart_item_ids']);
            }

            // Support direct product checkout (Buy Now) if cart items are empty and product_id is provided
            if ($cartItems->isEmpty() && ! empty($data['product_id'])) {
                $directProduct = Product::with(['seller.user', 'category'])->find($data['product_id']);
                if ($directProduct) {
                    $qty = max(1, (int) ($data['quantity'] ?? 1));
                    $syntheticItem = new CartItem([
                        'product_id' => $directProduct->id,
                        'quantity' => $qty,
                        'unit_price' => (float) $directProduct->price,
                        'item_type' => 'product',
                        'title' => $directProduct->title,
                        'image_url' => $directProduct->image_url,
                    ]);
                    $syntheticItem->setRelation('product', $directProduct);
                    $cartItems = collect([$syntheticItem]);
                }
            }

            if ($cartItems->isEmpty()) {
                throw new \Exception('No valid items selected for checkout.', 422);
            }

            $profileId = (int) $this->profileId();

            // Validate requested shipping method if provided
            $requestedShippingMethod = $data['shipping_method'] ?? null;
            if ($requestedShippingMethod !== null && ! ShippingOptionDTO::isValid($requestedShippingMethod)) {
                throw new \Exception("Invalid shipping method: {$requestedShippingMethod}.", 422);
            }
            $normalizedRequestedMethod = $requestedShippingMethod !== null
                ? ShippingOptionDTO::normalizeKey($requestedShippingMethod)
                : null;

            // Group items by seller to create separate orders per seller
            $sellerGroups = $cartItems->groupBy(function ($item) {
                if ($item->product_id) {
                    $product = $item->product ?? Product::find($item->product_id);
                    if ($product && ! $item->relationLoaded('product')) {
                        $item->setRelation('product', $product);
                    }

                    return $product?->seller_id;
                }
                if ($item->service_id) {
                    $service = $item->service ?? Service::find($item->service_id);
                    if ($service && ! $item->relationLoaded('service')) {
                        $item->setRelation('service', $service);
                    }

                    return $service?->provider_id;
                }
                if ($item->event_id) {
                    $event = $item->event ?? Event::find($item->event_id);
                    if ($event && ! $item->relationLoaded('event')) {
                        $item->setRelation('event', $event);
                    }

                    return $event?->organizer_id;
                }

                return null;
            });

            if ($sellerGroups->isEmpty()) {
                throw new \Exception('Unable to process checkout. Items have no seller.', 422);
            }

            $orders = [];

            $cartSelectedShipping = $this->cartManager->getSelectedShipping();

            foreach ($sellerGroups as $sellerId => $items) {
                $subtotal = $items->sum(fn ($i) => (float) $i->unit_price * $i->quantity);
                // Zero buyer platform fee
                $buyerFee = 0.00;
                $platformFee = 0.00;
                // Seller commission: exactly 2.5% platform fee deducted from seller
                $sellerFee = round($subtotal * 0.025, 2);

                $productItems = $items->filter(fn ($i) => $i->product_id && $i->product);
                $shippingMethod = null;
                $shippingAmount = 0.00;

                $selectedRateId       = $data['selected_rate_id'] ?? ($data['rate_id'] ?? ($cartSelectedShipping['rate_id'] ?? null));
                $selectedCarrierId   = $data['carrier_id'] ?? ($cartSelectedShipping['carrier_id'] ?? null);
                $selectedCarrierCode = $data['carrier_code'] ?? ($cartSelectedShipping['carrier_code'] ?? null);
                $selectedServiceCode = $data['service_code'] ?? ($cartSelectedShipping['service_code'] ?? null);
                $selectedServiceName = $data['service_name'] ?? ($cartSelectedShipping['service_name'] ?? null);
                $selectedRateSnapshot = $data['selected_rate_snapshot'] ?? ($cartSelectedShipping['selected_rate_snapshot'] ?? null);

                // Enrich from cache if only rate_id or carrier_id provided
                $lookupKey = $selectedRateId ?? $selectedCarrierId;
                if ($lookupKey && (! $selectedCarrierId || ! $selectedServiceCode)) {
                    $user      = \Illuminate\Support\Facades\Auth::guard('jwt')->user();
                    $pId       = $user->profile ? (int) $user->profile->id : (int) $user->id;
                    $cached    = \Illuminate\Support\Facades\Cache::get("checkout:rates:user:{$pId}", []);
                    $snap      = $cached[$lookupKey] ?? null;
                    if ($snap) {
                        $selectedRateId       = $selectedRateId       ?? ($snap['rate_id']       ?? null);
                        $selectedCarrierId    = $selectedCarrierId    ?? ($snap['carrier_id']    ?? null);
                        $selectedCarrierCode  = $selectedCarrierCode  ?? ($snap['carrier_code']  ?? ($snap['carrier_friendly_name'] ?? null));
                        $selectedServiceCode  = $selectedServiceCode  ?? ($snap['service_code']  ?? null);
                        $selectedServiceName  = $selectedServiceName  ?? ($snap['service_name']  ?? null);
                        $selectedRateSnapshot = $selectedRateSnapshot ?? $snap;
                        if (! isset($data['shipping_amount']) && isset($snap['shipping_amount'])) {
                            $data['shipping_amount'] = (float) $snap['shipping_amount'];
                        }
                        if (! isset($data['shipping_method']) && ! empty($snap['shipping_method'])) {
                            $normalizedRequestedMethod = ShippingOptionDTO::normalizeKey($snap['shipping_method']) ?? $normalizedRequestedMethod;
                        }
                    }
                }

                if ($productItems->isNotEmpty()) {
                    $shippingMethod = $normalizedRequestedMethod ?? ($cartSelectedShipping['shipping_method'] ?? ShippingOptionDTO::STANDARD_SHIPPING);

                    // Separate AliExpress items from Local products
                    $aliExpressItems = $productItems->filter(fn ($i) => strtolower((string) $i->product->source) === 'aliexpress' || ! empty($i->product->aliexpress_product_id));
                    $localItems = $productItems->diff($aliExpressItems);

                    $aliExpressShipping = round($aliExpressItems->sum(fn ($i) => (float) $i->product->aliexpress_shipping_cost), 2);
                    $localShipping = 0.00;

                    if ($localItems->isNotEmpty()) {
                        if (in_array($shippingMethod, [
                            ShippingOptionDTO::FREE_SHIPPING,
                            ShippingOptionDTO::LOCAL_PICKUP,
                            ShippingOptionDTO::LOCAL_SHIPPING,
                        ], true)) {
                            $localShipping = 0.00;
                        } elseif (isset($data['shipping_amount']) && is_numeric($data['shipping_amount'])) {
                            $localShipping = round((float) $data['shipping_amount'], 2);
                        } elseif ($cartSelectedShipping && isset($cartSelectedShipping['shipping_amount'])) {
                            $localShipping = round((float) $cartSelectedShipping['shipping_amount'], 2);
                        } elseif (! empty($shippingAddress) && ! empty($shippingAddress['line1'])) {
                            try {
                                $shipEngineService = app(\App\Services\ShipEngineService::class);
                                $liveRates = $shipEngineService->calculateRatesForPayload($localItems, $shippingAddress);
                                if (! empty($liveRates)) {
                                    $matched = collect($liveRates)->first(function ($r) use ($shippingMethod) {
                                        return ($r['shipping_option_key'] ?? '') === $shippingMethod || ($r['service_code'] ?? '') === $shippingMethod;
                                    }) ?? $liveRates[0];
                                    $localShipping = round((float) ($matched['shipping_amount'] ?? 0), 2);
                                }
                            } catch (\Throwable $e) {
                                Log::info('Checkout live rate calculate fallback: ' . $e->getMessage());
                            }
                        }

                        if ($localShipping <= 0.00 && ! in_array($shippingMethod, [ShippingOptionDTO::FREE_SHIPPING, ShippingOptionDTO::LOCAL_PICKUP, ShippingOptionDTO::LOCAL_SHIPPING], true)) {
                            $localShipping = round($localItems->sum(fn ($i) => (float) $i->product->getShippingOptionCost($shippingMethod)), 2);
                        }
                    }

                    $shippingAmount = round($aliExpressShipping + $localShipping, 2);
                }

                $total = round($subtotal + $shippingAmount, 2);

                // ── Duplicate order guard ─────────────────────────────────────
                // If a pending order already exists for this buyer+seller with the
                // same product IDs and total, return it instead of creating a new one.
                $cartProductIds = $productItems->pluck('product_id')->filter()->sort()->values()->toArray();
                if (! empty($cartProductIds)) {
                    $existingOrder = Order::where('buyer_id', $profileId)
                        ->where('seller_id', (int) $sellerId)
                        ->where('status', 'pending')
                        ->where('total', $total)
                        ->whereHas('items', function ($q) use ($cartProductIds) {
                            $q->whereIn('product_id', $cartProductIds);
                        })
                        ->latest()
                        ->first();

                    if ($existingOrder) {
                        $orders[] = $existingOrder->load('items.product');

                        continue;
                    }
                }
                // ─────────────────────────────────────────────────────────────

                // Generate a unique 8-digit tracking number with #ON- prefix
                do {
                    $randNum = (string) mt_rand(10000000, 99999999);
                    $trackingNumber = '#TRN-'.$randNum;
                } while (Order::where('tracking_number', $trackingNumber)->exists());

                $buyerProfile = Profile::with('user')->find($profileId);
                $savedAddresses = $buyerProfile ? $buyerProfile->savedAddresses()->latest('is_default')->get() : collect();

                $selectedAddress = null;
                if (! empty($data['shipping_address']) && is_array($data['shipping_address'])) {
                    $addr = $data['shipping_address'];
                    $selectedAddress = [
                        'name' => $addr['name'] ?? ($buyerProfile?->full_name),
                        'line1' => $addr['line1'] ?? ($addr['address_line1'] ?? ''),
                        'line2' => $addr['line2'] ?? ($addr['address_line2'] ?? null),
                        'city' => $addr['city'] ?? ($addr['city_locality'] ?? ''),
                        'state' => $addr['state'] ?? ($addr['state_province'] ?? ''),
                        'postal_code' => $addr['postal_code'] ?? ($addr['zip'] ?? ''),
                        'country' => $addr['country'] ?? ($addr['country_code'] ?? 'US'),
                        'phone' => $addr['phone'] ?? null,
                    ];
                } elseif (! empty($data['address_id'])) {
                    $found = $savedAddresses->firstWhere('id', (int) $data['address_id']);
                    if ($found) {
                        $selectedAddress = [
                            'name' => $found->name ?: $buyerProfile?->full_name,
                            'line1' => $found->address_line1,
                            'line2' => $found->address_line2,
                            'city' => $found->city_locality,
                            'state' => $found->state_province,
                            'postal_code' => $found->postal_code,
                            'country' => $found->country_code ?: 'US',
                            'phone' => $found->phone,
                        ];
                    }
                }

                if (! $selectedAddress) {
                    $defaultSaved = $savedAddresses->firstWhere('is_default', true) ?? $savedAddresses->first();
                    if ($defaultSaved) {
                        $selectedAddress = [
                            'name' => $defaultSaved->name ?: $buyerProfile?->full_name,
                            'line1' => $defaultSaved->address_line1,
                            'line2' => $defaultSaved->address_line2,
                            'city' => $defaultSaved->city_locality,
                            'state' => $defaultSaved->state_province,
                            'postal_code' => $defaultSaved->postal_code,
                            'country' => $defaultSaved->country_code ?: 'US',
                            'phone' => $defaultSaved->phone,
                        ];
                    } elseif ($buyerProfile && $buyerProfile->user && ! empty($buyerProfile->user->address_line_1)) {
                        $user = $buyerProfile->user;
                        $selectedAddress = [
                            'name' => $buyerProfile->full_name ?: $user->name,
                            'line1' => $user->address_line_1,
                            'line2' => $user->address_line_2,
                            'city' => $user->city ?? '',
                            'state' => $user->state ?? '',
                            'postal_code' => $user->zip ?? '',
                            'country' => $user->country ?: 'US',
                            'phone' => $user->phone ?? null,
                        ];
                    }
                }

                // If no shipping address could be resolved and cart has physical items
                $hasPhysicalProducts = $items->contains(fn ($item) => $item->item_type === 'product' || ! empty($item->product_id));
                if ($hasPhysicalProducts && (! $selectedAddress || empty($selectedAddress['line1']))) {
                    $addressUrl = url('/api/user/addresses');
                    throw new \Exception('No shipping address found. Please add an address at '.$addressUrl, 422);
                }

                $order = Order::create([
                    'buyer_id' => $profileId,
                    'seller_id' => (int) $sellerId,
                    'status' => 'pending',
                    'subtotal' => $subtotal,
                    'buyer_fee' => $buyerFee,
                    'seller_fee' => $sellerFee,
                    'platform_fee_total' => $platformFee,
                    'tax_amount' => 0,
                    'shipping_method' => $shippingMethod,
                    'shipping_amount' => $shippingAmount,
                    'shipping_currency' => 'USD',
                    'total' => $total,
                    'shipping_address' => $selectedAddress,
                    'tracking_number' => $trackingNumber,
                    'purchase_reference' => $trackingNumber,
                    'selected_rate_id' => $selectedRateId,
                    'selected_carrier_id' => $selectedCarrierId,
                    'selected_carrier_code' => $selectedCarrierCode,
                    'selected_service_code' => $selectedServiceCode,
                    'selected_service_name' => $selectedServiceName,
                    'selected_rate_snapshot' => $selectedRateSnapshot,
                ]);

                // Timeline: order placed.
                $this->timeline->record(
                    $order,
                    'pending',
                    $profileId,
                    OrderTimelineService::ACTOR_BUYER,
                    'Order placed'
                );

                // Create order items from cart items
                foreach ($items as $cartItem) {
                    $orderItem = $order->items()->create([
                        'product_id' => $cartItem->product_id,
                        'service_id' => $cartItem->service_id,
                        'booking_id' => $cartItem->booking_id,
                        'event_id' => $cartItem->event_id,
                        'title' => $cartItem->title,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $cartItem->unit_price,
                        'total_price' => (float) $cartItem->unit_price * $cartItem->quantity,
                    ]);

                    if ($cartItem->booking_id) {
                        $booking = Booking::find($cartItem->booking_id);
                        if ($booking) {
                            app(BookingManager::class)->activateBooking($booking);
                        }
                    }

                    if ($cartItem->item_type === 'ticket') {
                        $tickets = EventTicket::where('cart_item_id', $cartItem->id)->get();
                        foreach ($tickets as $ticket) {
                            $ticket->update(['order_item_id' => $orderItem->id]);
                            app(TicketManager::class)->confirmTicket($ticket);
                        }
                    }
                }

                // Create a pending transaction
                Transaction::create([
                    'order_id' => $order->id,
                    'buyer_id' => $profileId,
                    'seller_id' => (int) $sellerId,
                    'transaction_type' => 'sale',
                    'amount' => $total,
                    'platform_fee' => $sellerFee,
                    'seller_revenue' => round($subtotal - $sellerFee, 2),
                    'tax_amount' => 0,
                    'status' => 'pending',
                ]);

                $buyerName = Auth::guard('jwt')->user()?->profile?->full_name ?? 'A customer';
                $this->notificationManager->dispatch(
                    (int) $sellerId,
                    'store.order_received',
                    'New Order Received',
                    'You have received a new order for '.count($items)." item(s) from {$buyerName}.",
                    ['order_id' => $order->id]
                );

                $this->notificationManager->dispatch(
                    $profileId,
                    'store.order_placed',
                    'Order Placed Successfully',
                    "Your order #{$order->id} for ".count($items).' item(s) has been placed successfully.',
                    ['order_id' => $order->id]
                );

                // Send email order confirmation to the buyer
                $buyerUser = $buyerProfile?->user ?? Auth::guard('jwt')->user() ?? Auth::user();
                $buyerEmail = $buyerUser?->email ?? $buyerProfile?->email;
                if (! empty($buyerEmail)) {
                    $recipientName = $buyerProfile?->full_name ?: ($buyerUser?->name ?: 'Customer');
                    app(EmailNotificationService::class)->orderConfirmation($order, $buyerEmail, $recipientName);
                }

                $orders[] = $order;
            }

            // Clear temporary cart shipping selection after orders are placed
            $this->cartManager->clearSelectedShipping();

            // Create Stripe PaymentIntent (Flutter uses client_secret to pay)
            $clientSecret = null;
            $paymentIntentId = null;
            $paymentUrl = null;
            $requiresShippingRateSelection = false;
            $nextSteps = [];

            // Determine if dynamic carrier shipping rate selection is strictly required.
            // When the buyer has already chosen a valid shipping method (or default standard)
            // and the shipping_amount has been calculated and added to the order, rate selection
            // is complete. Buyers must NEVER be looped into live ShipEngine rate querying at checkout.
            $requiresDynamicShipping = false;
            foreach ($orders as $order) {
                // If the order has a settled shipping method and shipping amount, rate selection is done.
                if (! empty($order->shipping_method) && $order->shipping_amount !== null) {
                    continue;
                }

                // If any order has physical products without a settled shipping method/amount
                // and its provider mandates pre-payment rate selection:
                if ($this->fulfillmentResolver->orderRequiresPrePaymentShippingRate($order)) {
                    $requiresDynamicShipping = true;
                    break;
                }
            }

            if (! empty($orders) && ! $requiresDynamicShipping) {
                $user = Auth::guard('jwt')->user();
                try {
                    // Reuse existing PaymentIntent if all orders already have one
                    $existingIntentId = collect($orders)
                        ->filter(fn ($o) => ! empty($o->stripe_payment_intent_id))
                        ->first()?->stripe_payment_intent_id;

                    if ($existingIntentId) {
                        try {
                            $intent = $this->stripePaymentService->retrievePaymentIntent($existingIntentId);
                            $paymentIntentId = $intent->id;
                            $clientSecret = $intent->client_secret;
                        } catch (\Throwable) {
                            $existingIntentId = null;
                        }
                    }

                    if (! $existingIntentId) {
                        // 1. PaymentIntent — Flutter uses this (unchanged)
                        $paymentIntent = $this->stripePaymentService->createCombinedPaymentIntent($orders, $user);
                        $paymentIntentId = $paymentIntent->id;
                        $clientSecret = $paymentIntent->client_secret;

                        foreach ($orders as $order) {
                            $order->update(['stripe_payment_intent_id' => $paymentIntentId]);
                        }

                        Payment::create([
                            'order_id' => $orders[0]->id,
                            'user_id' => $user->id,
                            'payment_intent_id' => $paymentIntentId,
                            'amount' => array_reduce($orders, fn ($carry, $o) => $carry + $o->total, 0.0),
                            'currency' => strtoupper($paymentIntent->currency ?? 'USD'),
                            'payment_status' => 'pending',
                        ]);
                    }

                    // 2. Checkout Session — web users use payment_url
                    $session = $this->stripePaymentService->createCheckoutSession(
                        $orders,
                        $user,
                        $data['success_url'] ?? null,
                        $data['cancel_url'] ?? null
                    );
                    $paymentUrl = $session['url'] ?? null;

                    $firstOrderId = $orders[0]->id ?? null;
                    $nextSteps = [
                        'step_1' => $firstOrderId ? "Call GET /api/payments/{$firstOrderId}/pay (or use client_secret / payment_url) to complete payment." : 'Complete payment.',
                        'pay_endpoint' => $firstOrderId ? url("/api/payments/{$firstOrderId}/pay") : null,
                        'payment_url' => $paymentUrl,
                    ];

                } catch (\Exception $e) {
                    throw new \Exception('Stripe payment setup failed: '.$e->getMessage(), 500);
                }
            } elseif ($requiresDynamicShipping) {
                $requiresShippingRateSelection = true;
                $firstOrderId = $orders[0]->id ?? null;
                $nextSteps = [
                    'step_1' => 'Call POST /api/shipengine/get-rates with {"order_id": '.($firstOrderId ?? 'ID').'} to get rates.',
                    'step_2' => 'Select carrier rate before finalizing payment.',
                    'step_3' => 'Call GET /api/payments/'.($firstOrderId ?? 'order_id').'/pay to generate the final payment url and client secret.',
                ];
            }

            return [
                'orders' => $orders,
                'client_secret' => $clientSecret,
                'payment_intent_id' => $paymentIntentId,
                'payment_url' => $paymentUrl,
                'requires_shipping_rate_selection' => $requiresShippingRateSelection,
                'next_steps' => $nextSteps,
            ];
        });
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

    /**
     * Get a comprehensive preview of the checkout data before placing an order.
     * Allows the buyer to review all products with options, available shipping methods,
     * address choices, and financial breakdown before submitting POST /api/orders/checkout.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function preview(array $params = []): array
    {
        $user = Auth::guard('jwt')->user();
        if (! $user) {
            throw new \Exception('Unauthenticated. Please provide a valid Bearer token.', 401);
        }

        $profile = $user->profile;
        if (! $profile) {
            throw new \Exception('Profile not found. Please complete your profile first.', 400);
        }

        $cart = $this->cartManager->show();
        $cart->load([
            'items.product.seller.user',
            'items.product.category',
            'items.service.provider',
            'items.event.organizer',
        ]);

        $cartItems = $cart->items;
        if (isset($params['cart_item_ids']) && is_array($params['cart_item_ids']) && ! empty($params['cart_item_ids'])) {
            $cartItems = $cartItems->whereIn('id', array_map('intval', $params['cart_item_ids']));
        }

        // Direct product preview fallback (e.g. "Buy Now" or previewing a specific product before adding to cart)
        $isDirect = false;
        if ($cartItems->isEmpty() && ! empty($params['product_id'])) {
            $directProduct = Product::with(['seller.user', 'category'])->find($params['product_id']);
            if ($directProduct) {
                $qty = max(1, (int) ($params['quantity'] ?? 1));
                $syntheticItem = new CartItem([
                    'product_id' => $directProduct->id,
                    'quantity' => $qty,
                    'unit_price' => (float) $directProduct->price,
                    'item_type' => 'product',
                    'title' => $directProduct->title,
                    'image_url' => $directProduct->image_url,
                ]);
                $syntheticItem->setRelation('product', $directProduct);
                $cartItems = collect([$syntheticItem]);
                $isDirect = true;
            }
        }

        // Available saved addresses for this buyer
        $savedAddresses = $profile->savedAddresses()->latest('is_default')->get();

        // Determine active/selected shipping address
        $selectedAddress = null;
        if (! empty($params['shipping_address']) && is_array($params['shipping_address'])) {
            $addr = $params['shipping_address'];
            $selectedAddress = [
                'name' => $addr['name'] ?? ($profile->full_name),
                'line1' => $addr['line1'] ?? ($addr['address_line1'] ?? ''),
                'line2' => $addr['line2'] ?? ($addr['address_line2'] ?? null),
                'city' => $addr['city'] ?? ($addr['city_locality'] ?? ''),
                'state' => $addr['state'] ?? ($addr['state_province'] ?? ''),
                'postal_code' => $addr['postal_code'] ?? ($addr['zip'] ?? ''),
                'country' => $addr['country'] ?? ($addr['country_code'] ?? 'US'),
                'phone' => $addr['phone'] ?? null,
            ];
        } elseif (! empty($params['address_id'])) {
            $found = $savedAddresses->firstWhere('id', (int) $params['address_id']);
            if ($found) {
                $selectedAddress = [
                    'name' => $found->name ?: $profile->full_name,
                    'line1' => $found->address_line1,
                    'line2' => $found->address_line2,
                    'city' => $found->city_locality,
                    'state' => $found->state_province,
                    'postal_code' => $found->postal_code,
                    'country' => $found->country_code ?: 'US',
                    'phone' => $found->phone,
                ];
            }
        }

        if (! $selectedAddress) {
            $defaultSaved = $savedAddresses->where('is_default', true)->first() ?? $savedAddresses->first();
            if ($defaultSaved) {
                $selectedAddress = [
                    'name' => $defaultSaved->name ?: $profile->full_name,
                    'line1' => $defaultSaved->address_line1,
                    'line2' => $defaultSaved->address_line2,
                    'city' => $defaultSaved->city_locality,
                    'state' => $defaultSaved->state_province,
                    'postal_code' => $defaultSaved->postal_code,
                    'country' => $defaultSaved->country_code ?: 'US',
                    'phone' => $defaultSaved->phone,
                ];
            } elseif (! empty($user->address_line_1)) {
                $selectedAddress = [
                    'name' => $profile->full_name ?: $user->name,
                    'line1' => $user->address_line_1,
                    'line2' => $user->address_line_2,
                    'city' => $user->city ?? '',
                    'state' => $user->state ?? '',
                    'postal_code' => $user->zip ?? '',
                    'country' => $user->country ?: 'US',
                    'phone' => $user->phone ?? null,
                ];
            }
        }

        // Empty cart case
        if ($cartItems->isEmpty()) {
            return [
                'can_checkout' => false,
                'warnings' => ['Your cart is empty. Add items before proceeding to checkout.'],
                'items_count' => 0,
                'items' => [],
                'available_shipping_options' => [],
                'selected_shipping_method' => null,
                'selected_shipping_option' => null,
                'selected_shipping_address' => $selectedAddress,
                'saved_addresses' => $savedAddresses->map(fn ($a) => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'name' => $a->name,
                    'address_line1' => $a->address_line1,
                    'address_line2' => $a->address_line2,
                    'city_locality' => $a->city_locality,
                    'state_province' => $a->state_province,
                    'postal_code' => $a->postal_code,
                    'country_code' => $a->country_code,
                    'phone' => $a->phone,
                    'is_default' => (bool) $a->is_default,
                ])->values()->all(),
                'pricing_summary' => [
                    'subtotal' => 0.00,
                    'shipping_amount' => 0.00,
                    'buyer_fee' => 0.00,
                    'buyer_fee_rate' => '0%',
                    'platform_fee' => 0.00,
                    'platform_fee_rate' => '0%',
                    'seller_fee' => 0.00,
                    'seller_fee_rate' => '2.5%',
                    'tax_amount' => 0.00,
                    'total_amount' => 0.00,
                    'currency' => 'USD',
                ],
                'post_checkout_payload' => null,
            ];
        }

        $formattedItems = [];
        $subtotal = 0.00;

        foreach ($cartItems as $item) {
            $product = $item->product;
            $service = $item->service;
            $event = $item->event;

            $itemSubtotal = round((float) $item->unit_price * $item->quantity, 2);
            $subtotal += $itemSubtotal;

            $sellerData = null;
            if ($product && $product->seller) {
                $sellerData = [
                    'id' => $product->seller->id,
                    'full_name' => $product->seller->full_name,
                    'username' => $product->seller->username,
                    'avatar_url' => ImageManager::fileUrl($product->seller->avatar_url),
                ];
            } elseif ($service && $service->provider) {
                $sellerData = [
                    'id' => $service->provider->id,
                    'full_name' => $service->provider->full_name,
                    'username' => $service->provider->username,
                    'avatar_url' => ImageManager::fileUrl($service->provider->avatar_url),
                ];
            } elseif ($event && $event->organizer) {
                $sellerData = [
                    'id' => $event->organizer->id,
                    'full_name' => $event->organizer->full_name,
                    'username' => $event->organizer->username,
                    'avatar_url' => ImageManager::fileUrl($event->organizer->avatar_url),
                ];
            }

            $productDetails = null;
            if ($product) {
                $src = is_array($product->aliexpress_source_data) ? $product->aliexpress_source_data : [];

                $productDetails = [
                    'id' => $product->id,
                    'title' => $product->title,
                    'source' => $product->source ?? 'local',
                    'brand' => $product->brand ?? ($src['brand'] ?? null),
                    'sku' => $product->sku ?? ($product->aliexpress_sku ?? ($src['skus'][0]['sku_code'] ?? null)),
                    'stock_quantity' => (int) ($product->stock_quantity ?? 0),
                    'in_stock' => (int) ($product->stock_quantity ?? 0) >= $item->quantity,
                    'product_type' => strtolower((string) ($product->product_type ?? '')) === 'digital' ? 'digital' : 'physical',
                    'condition' => in_array(strtolower((string) ($product->condition ?? '')), ['new', 'used', 'refurbished'], true) ? strtolower((string) $product->condition) : 'new',
                    'product_variants' => $product->product_variants ?? [],
                    'available_shipping_options' => array_map(fn ($opt) => $opt->toArray(), $product->getEnabledShippingOptions()),
                    'shipping_strategy' => $product->shipping_strategy ?? 'manual',
                    'image_url' => ImageManager::fileUrl($product->image_url),
                ];
            }

            $formattedItems[] = [
                'id' => $item->id,
                'item_type' => $item->item_type,
                'product_id' => $item->product_id,
                'service_id' => $item->service_id,
                'event_id' => $item->event_id,
                'title' => $item->title,
                'image_url' => ImageManager::fileUrl($item->image_url ?: ($product?->image_url ?? ($service?->image_url ?? $event?->image_url))),
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => $itemSubtotal,
                'seller' => $sellerData,
                'product' => $productDetails,
            ];
        }

        // Available shipping options across all products in checkout (calculated with destination address, product dimensions, and weights)
        $availableShippingOptions = $this->computeShippingOptionsForItems($cartItems, $selectedAddress);

        // Requested or default shipping method
        $requestedMethod = $params['shipping_method'] ?? null;
        if ($requestedMethod !== null) {
            $requestedMethod = ShippingOptionDTO::normalizeKey($requestedMethod);
        } else {
            $requestedMethod = ShippingOptionDTO::STANDARD_SHIPPING;
        }

        // Calculate shipping amount
        $selectedShippingOption = null;
        $shippingAmount = 0.00;
        foreach ($availableShippingOptions as $opt) {
            if ($opt['key'] === $requestedMethod) {
                $selectedShippingOption = $opt;
                $shippingAmount = (float) $opt['cost'];
                break;
            }
        }

        $cartSelectedShipping = $this->cartManager->getSelectedShipping();
        $aliExpressShipping = 0.00;
        foreach ($cartItems as $ci) {
            $prod = $ci->product;
            if ($prod && (strtolower((string) $prod->source) === 'aliexpress' || ! empty($prod->aliexpress_product_id))) {
                $aliExpressShipping += (float) $prod->aliexpress_shipping_cost;
            }
        }

        // Check if a specific rate was selected or cached
        $selectedRateId = $params['selected_rate_id'] ?? ($params['rate_id'] ?? null);
        $cachedRateSnap = null;
        if ($selectedRateId) {
            $userProfileId = $profile->id ?? $user->id;
            $cachedRates = \Illuminate\Support\Facades\Cache::get("checkout:rates:user:{$userProfileId}", []);
            $cachedRateSnap = $cachedRates[$selectedRateId] ?? null;
        }

        if (isset($params['shipping_amount']) && is_numeric($params['shipping_amount'])) {
            $shippingAmount = round((float) $params['shipping_amount'], 2);
        } elseif ($cachedRateSnap && isset($cachedRateSnap['shipping_amount'])) {
            $shippingAmount = round((float) $cachedRateSnap['shipping_amount'], 2);
        } elseif ($cartSelectedShipping && isset($cartSelectedShipping['shipping_amount'])) {
            $requestedMethod = $cartSelectedShipping['shipping_method'] ?? $requestedMethod;
            if (in_array($requestedMethod, [
                ShippingOptionDTO::FREE_SHIPPING,
                ShippingOptionDTO::LOCAL_PICKUP,
                ShippingOptionDTO::LOCAL_SHIPPING,
            ], true)) {
                $shippingAmount = 0.00;
            } else {
                $shippingAmount = round($aliExpressShipping + (float) $cartSelectedShipping['shipping_amount'], 2);
            }
        } else {
            if (! $selectedShippingOption && ! empty($availableShippingOptions)) {
                $selectedShippingOption = $availableShippingOptions[0];
                $requestedMethod = $selectedShippingOption['key'];
                $shippingAmount = (float) $selectedShippingOption['cost'];
            }
            if (in_array($requestedMethod, [
                ShippingOptionDTO::FREE_SHIPPING,
                ShippingOptionDTO::LOCAL_PICKUP,
                ShippingOptionDTO::LOCAL_SHIPPING,
            ], true)) {
                $shippingAmount = 0.00;
            } else {
                $shippingAmount = round($aliExpressShipping + $shippingAmount, 2);
            }
        }

        // Zero buyer platform fee
        $buyerFee = 0.00;
        $platformFee = 0.00;
        $sellerFee = round($subtotal * 0.025, 2);
        $totalAmount = round($subtotal + $shippingAmount, 2);

        // Validation warnings
        $warnings = [];
        $hasPhysicalProducts = $cartItems->contains(fn ($item) => $item->item_type === 'product' || ! empty($item->product_id));

        if ($hasPhysicalProducts && (! $selectedAddress || empty($selectedAddress['line1']) || empty($selectedAddress['city']) || empty($selectedAddress['postal_code']))) {
            $warnings[] = 'Shipping address is missing or incomplete. Please add an address at '.url('/api/user/addresses');
        }

        foreach ($cartItems as $ci) {
            if ($ci->product && (int) ($ci->product->stock_quantity ?? 0) < $ci->quantity) {
                $warnings[] = "Product '{$ci->product->title}' has insufficient stock ({$ci->product->stock_quantity} available, requested {$ci->quantity}).";
            }
        }

        $canCheckout = empty($warnings);

        return [
            'can_checkout' => $canCheckout,
            'warnings' => $warnings,
            'items_count' => (int) $cartItems->sum('quantity'),
            'items' => $formattedItems,
            'available_shipping_options' => $availableShippingOptions,
            'selected_shipping_method' => $requestedMethod,
            'selected_shipping_option' => $selectedShippingOption,
            'selected_shipping_address' => $selectedAddress,
            'saved_addresses' => $savedAddresses->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->label,
                'name' => $a->name,
                'address_line1' => $a->address_line1,
                'address_line2' => $a->address_line2,
                'city_locality' => $a->city_locality,
                'state_province' => $a->state_province,
                'postal_code' => $a->postal_code,
                'country_code' => $a->country_code,
                'phone' => $a->phone,
                'is_default' => (bool) $a->is_default,
            ])->values()->all(),
            'subtotal' => $subtotal,
            'sub_total' => $subtotal,
            'shipping_amount' => $shippingAmount,
            'shipping_fee' => $shippingAmount,
            'shipping_total' => $shippingAmount,
            'total' => $totalAmount,
            'total_amount' => $totalAmount,
            'pricing_summary' => [
                'subtotal' => $subtotal,
                'sub_total' => $subtotal,
                'shipping_amount' => $shippingAmount,
                'shipping_fee' => $shippingAmount,
                'shipping_total' => $shippingAmount,
                'buyer_fee' => 0.00,
                'buyer_fee_rate' => '0%',
                'platform_fee' => 0.00,
                'platform_fee_rate' => '0%',
                'seller_fee' => $sellerFee,
                'seller_fee_rate' => '2.5%',
                'tax_amount' => 0.00,
                'total' => $totalAmount,
                'total_amount' => $totalAmount,
                'currency' => 'USD',
            ],
            'post_checkout_payload' => [
                'shipping_method' => $requestedMethod,
                'address_id' => ! empty($params['address_id']) ? (int) $params['address_id'] : null,
                'cart_item_ids' => $isDirect ? [] : $cartItems->filter(fn ($i) => ! empty($i->id))->pluck('id')->values()->all(),
                'product_id' => $isDirect && ! empty($params['product_id']) ? (int) $params['product_id'] : null,
                'quantity' => $isDirect ? max(1, (int) ($params['quantity'] ?? 1)) : null,
            ],
        ];
    }

    /**
     * Compute intersection of available shipping options across given items.
     * Accurately factors in destination address, origin ship_from, product dimensions, and weights.
     *
     * @return array<int, array<string, mixed>>
     */
    public function computeShippingOptionsForItems(iterable $items, ?array $destinationAddress = null): array
    {
        $products = collect($items)->filter(fn ($item) => ! empty($item->product))->map(fn ($item) => $item->product);

        if ($products->isEmpty()) {
            return app(\App\Services\ShipEngineClient::class)->getShippingRules();
        }

        // 1. Calculate intersection of enabled shipping option keys across all products
        $intersection = null;
        foreach ($products as $product) {
            $enabled = $product->getEnabledShippingOptionKeys();
            if ($intersection === null) {
                $intersection = $enabled;
            } else {
                $intersection = array_values(array_intersect($intersection, $enabled));
            }
        }

        if (empty($intersection)) {
            $intersection = [ShippingOptionDTO::STANDARD_SHIPPING];
        }

        // 2. Query live courier rates if destination address is available
        $liveRates = [];
        if (! empty($destinationAddress) && ! empty($destinationAddress['line1'])) {
            try {
                $shipEngineService = app(\App\Services\ShipEngineService::class);
                $liveRates = $shipEngineService->calculateRatesForPayload($items, $destinationAddress);
            } catch (\Throwable $e) {
                Log::info('computeShippingOptionsForItems live rate calculation: ' . $e->getMessage());
            }
        }

        // 3. Build DTO array with accurate calculated cost for each option across all items
        $results = [];
        foreach ($intersection as $optionKey) {
            $totalCost = 0.00;
            if (in_array($optionKey, [
                ShippingOptionDTO::FREE_SHIPPING,
                ShippingOptionDTO::LOCAL_PICKUP,
                ShippingOptionDTO::LOCAL_SHIPPING,
            ], true)) {
                $totalCost = 0.00;
            } else {
                // If live courier rate exists for this option key, use the lowest rate
                $matchedLiveRates = array_filter($liveRates, function ($r) use ($optionKey) {
                    $rateKey = $r['shipping_option_key'] ?? ($r['service_code'] ?? '');
                    return $rateKey === $optionKey;
                });

                if (! empty($matchedLiveRates)) {
                    $totalCost = (float) min(array_column($matchedLiveRates, 'shipping_amount'));
                } else {
                    foreach ($items as $item) {
                        if ($item->product) {
                            $totalCost += $item->product->getShippingOptionCost($optionKey);
                        }
                    }
                }
            }

            $dto = ShippingOptionDTO::make($optionKey, round($totalCost, 2));
            $results[] = $dto->toArray();
        }

        return $results;
    }

    /**
     * Compute available shipping options across products in the cart or for a specific product.
     *
     * @param  array<int, int>|null  $cartItemIds
     * @param  int|null  $productId
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableShippingOptions(?array $cartItemIds = null, ?int $productId = null): array
    {
        if ($productId) {
            $product = Product::find($productId);
            if ($product) {
                if ($product->source === 'aliexpress') {
                    $aliCost = (float) ($product->aliexpress_shipping_cost ?: ($product->shipping_cost ?? 0.00));
                    $aliEta = $product->estimated_delivery_time ?: ($product->estimated_delivery ?: '10-15 business days');

                    return [
                        [
                            'rule_code' => 'STANDARD_SHIPPING',
                            'rule_id' => 'ae-shipping-standard',
                            'rule_type' => 'Supplier Fixed SLA',
                            'key' => 'standard_shipping',
                            'name' => 'Standard Tracked Shipping',
                            'cost' => $aliCost,
                            'estimated_delivery' => $aliEta,
                            'description' => 'Supplier tracked international courier delivery.',
                            'is_available' => true,
                        ],
                    ];
                }

                $fakeItem = (object) [
                    'product' => $product,
                    'quantity' => 1,
                ];

                return $this->computeShippingOptionsForItems([$fakeItem]);
            }
        }

        $cart = $this->cartManager->show();
        $cart->load('items.product');

        $items = $cart->items;
        if (! empty($cartItemIds)) {
            $items = $items->whereIn('id', $cartItemIds);
        }

        $products = $items->filter(fn ($item) => ! empty($item->product))->map(fn ($item) => $item->product);
        $hasOnlyAli = $products->isNotEmpty() && $products->every(fn ($p) => $p->source === 'aliexpress');
        if ($hasOnlyAli) {
            $first = $products->first();
            $aliCost = (float) ($first->aliexpress_shipping_cost ?: ($first->shipping_cost ?? 0.00));
            $aliEta = $first->estimated_delivery_time ?: ($first->estimated_delivery ?: '10-15 business days');

            return [
                [
                    'rule_code' => 'STANDARD_SHIPPING',
                    'rule_id' => 'ae-shipping-standard',
                    'rule_type' => 'Supplier Fixed SLA',
                    'key' => 'standard_shipping',
                    'name' => 'Standard Tracked Shipping',
                    'cost' => $aliCost,
                    'estimated_delivery' => $aliEta,
                    'description' => 'Supplier tracked international courier delivery.',
                    'is_available' => true,
                ],
            ];
        }

        return $this->computeShippingOptionsForItems($items);
    }
}
