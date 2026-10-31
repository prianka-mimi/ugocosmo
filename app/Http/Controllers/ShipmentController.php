<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Exceptions\ShipEngine\ShipEngineAuthenticationException;
use App\Http\Requests\GetShippingRatesRequest;
use App\Http\Requests\PurchaseShippingLabelRequest;
use App\Http\Requests\StoreShipmentRequest;
use App\Http\Resources\ShipmentResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Profile;
use App\DTOs\ShippingOptionDTO;
use App\Models\SavedAddress;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentService;
use App\Services\ShippingLabelPdfService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Shipping endpoints (ShipEngine / ShipStation API). Thin orchestration —
 * all business rules live in ShipmentService, the HTTP transport in
 * ShipEngineApiClient.
 */
class ShipmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected ShipmentService $service) {}

    /**
     * List Shipments (both purchases and sales for the logged in user)
     * GET /api/shipping/shipments
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $profile = $this->profile();
            $perPage = min((int) $request->input('per_page', 20), 50);

            $shipments = $this->service->forUser((int) $profile->id, $perPage);

            return ApiResponse::paginated($shipments, ShipmentResource::collection($shipments), 'Your shipments retrieved.');
        } catch (Throwable $e) {
            Log::error('Shipments list failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Download / Preview / Re-print Shipping Label
     * GET /api/shipping/download-label?shipment_id=49
     */
    public function downloadLabel(Request $request, ?Shipment $shipment = null): SymfonyResponse
    {
        try {
            // Auth optional — public endpoint so browser/Flutter can open URL directly
            try { $this->resolveUser($request); } catch (Throwable $ignored) {}

            if (! $shipment) {
                $labelId = (int) $request->input('label_id', $request->query('label_id'));
                if (! $labelId) {
                    return ApiResponse::error('label_id is required.', 422);
                }
                $shipment = Shipment::find($labelId);
                if (! $shipment) {
                    return ApiResponse::error('Label not found.', 404);
                }
            }

            if (! $shipment->hasActiveLabel()) {
                return ApiResponse::error('No active label found for this shipment.', 404);
            }

            // 1. Explicit download requested (?download=1 or ?mode=download)
            if ($request->boolean('download') || $request->query('download') === '1' || $request->query('mode') === 'download') {
                return app(ShippingLabelPdfService::class)->renderResponse($shipment, inline: false);
            }

            // 2. Explicit preview requested (?preview=1 or ?mode=preview)
            if ($request->boolean('preview') || $request->query('preview') === '1' || $request->query('mode') === 'preview') {
                return app(ShippingLabelPdfService::class)->renderResponse($shipment, inline: true);
            }

            // 3. Browser direct navigation without JSON preference (renders PDF inline in browser)
            $accept = (string) $request->header('Accept', '');
            if (! $request->expectsJson() && (str_contains($accept, 'text/html') || str_contains($accept, 'application/pdf'))) {
                return app(ShippingLabelPdfService::class)->renderResponse($shipment, inline: true);
            }

            // 4. Standard API JSON response (returns download button URL and preview button URL)
            return ApiResponse::success([
                'label' => [
                    'label_id'           => $shipment->id,
                    'label_format'       => $shipment->label_format ?? 'pdf',
                    'label_download_url' => url('/api/shipping/download-label') . '?label_id=' . $shipment->id . '&download=1',
                    'label_preview_url'  => url('/api/shipping/download-label') . '?label_id=' . $shipment->id . '&preview=1',
                    'tracking_number'    => $shipment->tracking_number,
                    'label_created_at'   => $shipment->label_created_at?->toIso8601String(),
                ],
            ], 'Label download URL retrieved.');
        } catch (Throwable $e) {
            Log::error('Label download failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Get Shipment Details
     */
    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        try {
            $this->resolveUser($request);
            $this->authorize('view', $shipment);

            $profile = $this->profile($request);
            $shipment = $this->service->show($shipment, (int) $profile->id);

            return ApiResponse::success(['shipment' => new ShipmentResource($shipment)], 'Shipment retrieved.');
        } catch (Throwable $e) {
            Log::error('Shipment retrieve failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Estimate Shipping Rates
     * POST /api/shipping/rates
     *
     * Accessible strictly by the Seller who owns the order/items or an Admin.
     * Buyers are explicitly denied (403 Forbidden) to prevent exposure of
     * wholesale/carrier label costs to customers.
     */
    public function rates(GetShippingRatesRequest $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            $order = Order::with(['items.product'])->findOrFail((int) $request->validated('order_id'));
            $validated = $request->validated();

            $this->authorizeSellerOrAdmin($user, $order);

            $sellerId = (int) ($order->seller_id ?: ($user->profile?->id ?? $user->id));

            $rates = $this->service->rates(
                $order,
                $sellerId,
                $validated['rate_options'] ?? [],
                $validated['validate_address'] ?? null,
            );

            return ApiResponse::success(['rates' => $rates], 'Shipping rates retrieved.');
        } catch (ShipEngineAuthenticationException $e) {
            Log::error('ShipEngine rates: API key invalid or not configured — '.$e->getMessage());

            if (! app()->isProduction()) {
                Log::warning('ShipEngine authentication failed in non-production, returning mock rates: '.$e->getMessage());
                $mockRates = $this->service->mockRates($order);

                return ApiResponse::success(['rates' => $mockRates], 'Shipping rates retrieved (sandbox/mock mode).');
            }

            return ApiResponse::error('Shipping service is not configured. Please contact support.', 503);
        } catch (Throwable $e) {
            Log::error('Shipping rates failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Get Shipping Rates for checkout / cart (buyer side)
     * POST /api/shipengine/get-rates
     *
     * Validates mandatory destination address (Ship To).
     * Calculates ShipEngine rates for local products and excludes AliExpress items.
     * Filters rates by seller's allowed_shipping_options.
     */
    public function getRates(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            $profileId = $user->profile?->id ?? (int) $user->id;

            $orderId = $request->input('order_id');
            $shipTo = $request->input('ship_to');

            // Dynamically resolve destination address from address_id, default saved address, or user address
            if (! empty($request->input('address_id'))) {
                $saved = SavedAddress::where('id', (int) $request->input('address_id'))
                    ->where(function ($q) use ($profileId, $user) {
                        $q->where('profile_id', $profileId);
                        if ($user->profile) {
                            $q->orWhere('profile_id', $user->profile->id);
                        }
                    })
                    ->first();
                if ($saved) {
                    $shipTo = $saved->toShippingArray();
                }
            }

            if (empty($shipTo) || (empty($shipTo['line1']) && empty($shipTo['address_line1']))) {
                $defaultSaved = SavedAddress::where(function ($q) use ($profileId, $user) {
                    $q->where('profile_id', $profileId);
                    if ($user->profile) {
                        $q->orWhere('profile_id', $user->profile->id);
                    }
                })->orderByDesc('is_default')->first();

                if ($defaultSaved) {
                    $shipTo = $defaultSaved->toShippingArray();
                } elseif (! empty($user->address_line_1)) {
                    $shipTo = [
                        'name' => $user->profile?->full_name ?: $user->name,
                        'line1' => $user->address_line_1,
                        'address_line1' => $user->address_line_1,
                        'city' => $user->city ?? '',
                        'city_locality' => $user->city ?? '',
                        'state' => $user->state ?? '',
                        'state_province' => $user->state ?? '',
                        'postal_code' => $user->postal_code ?: ($user->zip ?? ''),
                        'country' => $user->country ?: 'US',
                        'country_code' => $user->country ?: 'US',
                        'phone' => $user->mobile_number ?: ($user->phone ?? null),
                    ];
                }
            }

            // 1. If order_id provided, calculate for order
            if (! empty($orderId)) {
                $order = Order::with(['items.product.seller.user'])->findOrFail((int) $orderId);
                $this->authorizeSellerOrAdmin($user, $order);

                $destAddress = $shipTo ?? $order->shipping_address;
                if (empty($destAddress) || (empty($destAddress['line1']) && empty($destAddress['address_line1']))) {
                    return ApiResponse::error('Destination address (ship_to) is required to calculate shipping rates.', 422);
                }

                // If dropshipped AliExpress items only
                if ($order->isAliExpressFulfilled()) {
                    return ApiResponse::success([
                        'rates' => [],
                        'is_aliexpress' => true,
                        'message' => 'AliExpress dropshipping items use AliExpress logistics and cannot use ShipEngine.',
                    ], 'Shipping rates retrieved.');
                }

                $rates = $this->service->calculateRatesForPayload(
                    $order->items,
                    $destAddress,
                    $request->input('rate_options', []),
                    $request->input('validate_address')
                );

                return ApiResponse::success(['rates' => $rates], 'Shipping rates retrieved.');
            }

            // 2. Cart / Pre-order rate quoting (buyer checkout flow)
            if (empty($shipTo) || (empty($shipTo['line1']) && empty($shipTo['address_line1']))) {
                return ApiResponse::error('No shipping address found. Please save an address first or provide address_id / ship_to.', 422);
            }

            // Resolve items
            $items = collect();
            if ($request->filled('product_id')) {
                $product = Product::with(['seller.user'])->findOrFail((int) $request->input('product_id'));
                $qty = max(1, (int) $request->input('quantity', 1));
                $items = collect([['product' => $product, 'product_id' => $product->id, 'quantity' => $qty]]);
            } else {
                $cart = Cart::where('user_id', $profileId)->with(['items.product.seller.user'])->first();
                if ($cart && $cart->items->isNotEmpty()) {
                    $cartItems = $cart->items;
                    if ($request->filled('cart_item_ids')) {
                        $cIds = (array) $request->input('cart_item_ids');
                        $cartItems = $cartItems->whereIn('id', array_map('intval', $cIds));
                    }
                    $items = $cartItems;
                }
            }

            if ($items->isEmpty()) {
                return ApiResponse::error('No items found in cart to calculate shipping rates for.', 422);
            }

            // If the item or items are purely AliExpress dropshipping, return supplier bound rate & SLA
            $isAllAliExpress = $items->every(function ($i) {
                $prod = is_array($i) ? ($i['product'] ?? null) : ($i->product ?? null);

                return $prod && (strtolower((string) $prod->source) === 'aliexpress' || ! empty($prod->aliexpress_product_id));
            });

            if ($isAllAliExpress) {
                $aeRates = [];
                foreach ($items as $i) {
                    $prod = is_array($i) ? ($i['product'] ?? null) : ($i->product ?? null);
                    if ($prod) {
                        $cost = (float) ($prod->aliexpress_shipping_cost ?: ($prod->shipping_cost ?? 0.0));
                        $eta = (string) ($prod->estimated_delivery_time ?: ($prod->estimated_delivery ?: '10-15 business days'));
                        $aeRates[] = [
                            'rate_id' => 'ae-fixed-rate-'.$prod->id,
                            'carrier_id' => 'aliexpress_standard',
                            'carrier_code' => 'aliexpress_shipping',
                            'carrier_friendly_name' => 'AliExpress Logistics',
                            'service_code' => 'aliexpress_standard',
                            'service_type' => 'AliExpress Standard Shipping',
                            'shipping_amount' => $cost,
                            'currency' => 'USD',
                            'delivery_days' => 14,
                            'estimated_delivery' => $eta,
                            'is_aliexpress' => true,
                            'trackable' => true,
                        ];
                    }
                }

                return ApiResponse::success(['rates' => $aeRates], 'AliExpress pre-synced shipping rates retrieved.');
            }

            $rates = $this->service->calculateRatesForPayload(
                $items,
                $shipTo,
                $request->input('rate_options', []),
                $request->input('validate_address')
            );

            // Enrich each rate with shipping_option_key and service_name for UI grouping
            $rates = array_map(function (array $r) {
                $serviceText = strtolower(implode(' ', array_filter([
                    (string) ($r['service_code'] ?? ''),
                    (string) ($r['service_type'] ?? ''),
                    (string) ($r['service_name'] ?? ''),
                    (string) ($r['carrier_friendly_name'] ?? ''),
                ])));

                $deliveryDays = (int) ($r['delivery_days'] ?? 0);
                $isSlowOrPostal = str_contains($serviceText, 'airmail')
                    || str_contains($serviceText, 'economy')
                    || str_contains($serviceText, 'postal')
                    || str_contains($serviceText, 'standard')
                    || ($r['service_code'] ?? '') === 'international_shipping'
                    || ($r['service_type'] ?? '') === 'International Shipping'
                    || ($deliveryDays > 5);

                $isExpress = ! $isSlowOrPostal && (
                    str_contains($serviceText, 'express')
                    || str_contains($serviceText, '2day')
                    || str_contains($serviceText, '1day')
                    || str_contains($serviceText, 'overnight')
                    || str_contains($serviceText, 'next_day')
                    || str_contains($serviceText, 'next day')
                    || str_contains($serviceText, 'same_day')
                    || str_contains($serviceText, 'track_24')
                    || str_contains($serviceText, 'track 24')
                    || str_contains($serviceText, 'special_delivery')
                    || str_contains($serviceText, 'priority_mail_express')
                );

                $isIntl = str_contains($serviceText, 'international')
                    || str_contains($serviceText, 'intl')
                    || str_contains($serviceText, 'worldwide')
                    || str_contains($serviceText, 'global');

                $optKey = $isExpress ? 'express_shipping' : ($isIntl ? 'international_shipping' : 'standard_shipping');
                if ($optKey === 'international_shipping' && in_array($r['service_type'] ?? '', ['Standard Shipping', 'Standard Delivery'], true)) {
                    $r['service_type'] = 'International Delivery';
                }
                $r['shipping_option_key'] = $optKey;
                $r['service_name'] = $r['service_name'] ?? ($r['service_type'] ?? ($r['carrier_friendly_name'] ?? ($optKey === 'international_shipping' ? 'International Delivery' : 'Standard Delivery')));
                if ($optKey === 'international_shipping' && in_array($r['service_name'], ['Standard Shipping', 'Standard Delivery'], true)) {
                    $r['service_name'] = 'International Delivery';
                }

                return $r;
            }, $rates);

            // Optional filter by shipping_option if requested by UI (e.g. Standard, Express)
            $selectedOption = $request->input('shipping_option')
                ?? $request->input('category')
                ?? $request->input('shipping_method');

            if ($selectedOption) {
                $rates = $this->service->filterRatesByAllowedOptions($rates, [$selectedOption]);
            }

            return ApiResponse::success(['rates' => array_values($rates)], 'Shipping rates retrieved.');
        } catch (ShipEngineAuthenticationException $e) {
            Log::error('ShipEngine getRates authentication failed: '.$e->getMessage());

            if (! app()->isProduction()) {
                return ApiResponse::success(['rates' => []], 'Shipping rates retrieved (sandbox mode).');
            }

            return ApiResponse::error('Shipping service is not configured. Please contact support.', 503);
        } catch (Throwable $e) {
            Log::error('ShipEngine getRates failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Buyer Shipping Methods with Nested Carrier Rates
     * GET /api/buyer/shipping-methods
     * GET /api/cart/shipping-methods
     * GET /api/shipping/methods
     *
     * Returns the shipping options enabled by the seller for the products in cart
     * (or single product_id), with dynamically resolved live carrier rates nested under
     * each method.
     */
    public function buyerShippingMethods(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            $profileId = $user->profile ? (int) $user->profile->id : (int) $user->id;

            // 1. Resolve destination address dynamically
            $shipTo = null;
            if ($request->filled('address_id')) {
                $saved = SavedAddress::where('id', (int) $request->input('address_id'))
                    ->where('profile_id', $profileId)
                    ->first();
                if ($saved) {
                    $shipTo = [
                        'id' => $saved->id,
                        'name' => $saved->name ?: ($user->profile?->full_name ?: $user->name),
                        'line1' => $saved->address_line1,
                        'line2' => $saved->address_line2,
                        'city' => $saved->city_locality,
                        'state' => $saved->state_province,
                        'postal_code' => $saved->postal_code,
                        'country' => $saved->country_code ?: 'US',
                        'phone' => $saved->phone ?: ($user->mobile_number ?: $user->phone),
                    ];
                }
            }

            if (! $shipTo && $request->filled('ship_to') && is_array($request->input('ship_to'))) {
                $raw = $request->input('ship_to');
                $shipTo = [
                    'id' => null,
                    'name' => $raw['name'] ?? ($user->profile?->full_name ?: $user->name),
                    'line1' => $raw['line1'] ?? ($raw['address_line1'] ?? ''),
                    'line2' => $raw['line2'] ?? ($raw['address_line2'] ?? null),
                    'city' => $raw['city'] ?? ($raw['city_locality'] ?? ''),
                    'state' => $raw['state'] ?? ($raw['state_province'] ?? ''),
                    'postal_code' => $raw['postal_code'] ?? ($raw['postalCode'] ?? ($raw['zip'] ?? '')),
                    'country' => $raw['country'] ?? ($raw['country_code'] ?? 'US'),
                    'phone' => $raw['phone'] ?? null,
                ];
            }

            if (! $shipTo) {
                $defaultSaved = SavedAddress::where('profile_id', $profileId)
                    ->orderByDesc('is_default')
                    ->first();
                if ($defaultSaved) {
                    $shipTo = [
                        'id' => $defaultSaved->id,
                        'name' => $defaultSaved->name ?: ($user->profile?->full_name ?: $user->name),
                        'line1' => $defaultSaved->address_line1,
                        'line2' => $defaultSaved->address_line2,
                        'city' => $defaultSaved->city_locality,
                        'state' => $defaultSaved->state_province,
                        'postal_code' => $defaultSaved->postal_code,
                        'country' => $defaultSaved->country_code ?: 'US',
                        'phone' => $defaultSaved->phone ?: ($user->mobile_number ?: $user->phone),
                    ];
                } elseif (! empty($user->address_line_1)) {
                    $shipTo = [
                        'id' => null,
                        'name' => $user->profile?->full_name ?: $user->name,
                        'line1' => $user->address_line_1,
                        'line2' => $user->address_line_2,
                        'city' => $user->city ?? '',
                        'state' => $user->state ?? '',
                        'postal_code' => $user->zip ?? '',
                        'country' => $user->country ?: 'US',
                        'phone' => $user->mobile_number ?: ($user->phone ?? null),
                    ];
                }
            }

            $hasAddress = ! empty($shipTo) && (! empty($shipTo['line1']) || ! empty($shipTo['address_line1']));

            // 2. Resolve items
            $items = collect();
            if ($request->filled('product_id')) {
                $product = Product::with(['seller.user'])->find((int) $request->input('product_id'));
                if (! $product) {
                    return ApiResponse::error('Product not found.', 404);
                }
                $qty = max(1, (int) $request->input('quantity', 1));
                $items = collect([(object) [
                    'id' => null,
                    'product' => $product,
                    'product_id' => $product->id,
                    'quantity' => $qty,
                ]]);
            } else {
                $cart = Cart::where('user_id', $profileId)->with(['items.product.seller.user'])->first();
                if ($cart && $cart->items->isNotEmpty()) {
                    $cartItems = $cart->items;
                    if ($request->filled('cart_item_ids')) {
                        $cIds = is_array($request->input('cart_item_ids'))
                            ? $request->input('cart_item_ids')
                            : explode(',', (string) $request->input('cart_item_ids'));
                        $cIds = array_filter(array_map('intval', (array) $cIds));
                        if (! empty($cIds)) {
                            $cartItems = $cartItems->whereIn('id', $cIds);
                        }
                    }
                    $items = $cartItems->values();
                }
            }

            if ($items->isEmpty()) {
                return ApiResponse::success([
                    'has_shipping_address' => $hasAddress,
                    'shipping_address' => $shipTo,
                    'items_count' => 0,
                    'shipping_methods' => [],
                ], 'Cart is empty. No shipping methods available.');
            }

            // 3. Determine enabled shipping option keys across all products in the selection
            $products = $items->filter(fn ($i) => ! empty($i->product))->map(fn ($i) => $i->product);
            $intersection = null;
            foreach ($products as $prod) {
                $enabled = $prod->getEnabledShippingOptionKeys();
                if ($intersection === null) {
                    $intersection = $enabled;
                } else {
                    $intersection = array_values(array_intersect($intersection, $enabled));
                }
            }

            if (empty($intersection)) {
                $intersection = [ShippingOptionDTO::STANDARD_SHIPPING];
            }

            // 4. Calculate live carrier rates if address is present
            $allRates = [];
            $hasAddress = ! empty($shipTo) && (! empty($shipTo['line1']) || ! empty($shipTo['address_line1']));

            if ($hasAddress) {
                $isAllAliExpress = $products->isNotEmpty() && $products->every(fn ($p) => strtolower((string) $p->source) === 'aliexpress' || ! empty($p->aliexpress_product_id));

                if ($isAllAliExpress) {
                    foreach ($items as $i) {
                        $prod = $i->product;
                        if ($prod) {
                            $cost = (float) ($prod->aliexpress_shipping_cost ?: ($prod->shipping_cost ?? 0.0));
                            $eta = (string) ($prod->estimated_delivery_time ?: ($prod->estimated_delivery ?: '10-15 business days'));
                            $allRates[] = [
                                'rate_id' => 'ae-fixed-rate-'.$prod->id,
                                'carrier_id' => 'aliexpress_standard',
                                'carrier_code' => 'aliexpress_shipping',
                                'carrier_friendly_name' => 'AliExpress Logistics',
                                'service_code' => 'aliexpress_standard',
                                'service_type' => 'AliExpress Standard Shipping',
                                'service_name' => 'AliExpress Standard Shipping',
                                'shipping_amount' => $cost,
                                'currency' => 'USD',
                                'delivery_days' => 14,
                                'estimated_delivery' => $eta,
                                'is_aliexpress' => true,
                                'shipping_option_key' => 'standard_shipping',
                                'trackable' => true,
                            ];
                        }
                    }
                } else {
                    $calculatedRates = $this->service->calculateRatesForPayload(
                        $items,
                        $shipTo,
                        $request->input('rate_options', []),
                        $request->input('validate_address')
                    );

                    foreach ($calculatedRates as $r) {
                        $serviceText = strtolower(implode(' ', array_filter([
                            (string) ($r['service_code'] ?? ''),
                            (string) ($r['service_type'] ?? ''),
                            (string) ($r['service_name'] ?? ''),
                            (string) ($r['carrier_friendly_name'] ?? ''),
                        ])));

                        $deliveryDays = (int) ($r['delivery_days'] ?? 0);
                        $isSlowOrPostal = str_contains($serviceText, 'airmail')
                            || str_contains($serviceText, 'economy')
                            || str_contains($serviceText, 'postal')
                            || str_contains($serviceText, 'standard')
                            || ($r['service_code'] ?? '') === 'international_shipping'
                            || ($r['service_type'] ?? '') === 'International Shipping'
                            || ($deliveryDays > 5);

                        $isExpress = ! $isSlowOrPostal && (
                            str_contains($serviceText, 'express')
                            || str_contains($serviceText, '2day')
                            || str_contains($serviceText, '1day')
                            || str_contains($serviceText, 'overnight')
                            || str_contains($serviceText, 'next_day')
                            || str_contains($serviceText, 'next day')
                            || str_contains($serviceText, 'same_day')
                            || str_contains($serviceText, 'track_24')
                            || str_contains($serviceText, 'track 24')
                            || str_contains($serviceText, 'special_delivery')
                            || str_contains($serviceText, 'priority_mail_express')
                        );

                        $isIntl = str_contains($serviceText, 'international')
                            || str_contains($serviceText, 'intl')
                            || str_contains($serviceText, 'worldwide')
                            || str_contains($serviceText, 'global');

                        $optKey = $isExpress ? 'express_shipping' : ($isIntl ? 'international_shipping' : 'standard_shipping');
                        if ($optKey === 'international_shipping' && in_array($r['service_type'] ?? '', ['Standard Shipping', 'Standard Delivery'], true)) {
                            $r['service_type'] = 'International Delivery';
                        }
                        $r['shipping_option_key'] = $optKey;
                        $r['service_name'] = $r['service_name'] ?? ($r['service_type'] ?? ($r['carrier_friendly_name'] ?? ($optKey === 'international_shipping' ? 'International Delivery' : 'Standard Delivery')));
                        if ($optKey === 'international_shipping' && in_array($r['service_name'], ['Standard Shipping', 'Standard Delivery'], true)) {
                            $r['service_name'] = 'International Delivery';
                        }

                        $allRates[] = $r;
                    }
                }
            }

            // 5. Build shipping methods with nested carrier rates
            $shippingMethods = [];
            foreach ($intersection as $optionKey) {
                $totalBaseCost = 0.0;
                foreach ($products as $prod) {
                    $totalBaseCost += $prod->getShippingOptionCost($optionKey);
                }

                $dto = ShippingOptionDTO::make($optionKey, $totalBaseCost);

                // Filter rates matching this option key
                $methodRates = array_values(array_filter($allRates, function ($r) use ($optionKey) {
                    return ($r['shipping_option_key'] ?? '') === $optionKey;
                }));

                // Fallback default carrier rate if live provider rates are empty but address was provided
                if (empty($methodRates) && $hasAddress) {
                    $methodRates[] = [
                        'rate_id' => 'std-rate-'.$optionKey,
                        'carrier_id' => 'standard_carrier',
                        'carrier_code' => 'standard',
                        'carrier_friendly_name' => $dto->name,
                        'service_code' => $optionKey,
                        'service_type' => $dto->name,
                        'service_name' => $dto->name,
                        'shipping_amount' => round($dto->cost, 2),
                        'currency' => 'USD',
                        'delivery_days' => $optionKey === 'express_shipping' ? 2 : ($optionKey === 'international_shipping' ? 14 : 5),
                        'estimated_delivery' => $dto->estimatedDelivery,
                        'shipping_option_key' => $optionKey,
                        'trackable' => true,
                    ];
                }

                $minAmount = ! empty($methodRates)
                    ? min(array_column($methodRates, 'shipping_amount'))
                    : $dto->cost;

                $shippingMethods[] = [
                    'key' => $dto->key,
                    'name' => $dto->name,
                    'estimated_delivery' => $dto->estimatedDelivery,
                    'description' => $dto->description,
                    'is_available' => true,
                    'starting_from' => round((float) $minAmount, 2),
                    'rates_count' => count($methodRates),
                    'rates' => $methodRates,
                ];
            }

            return ApiResponse::success([
                'has_shipping_address' => $hasAddress,
                'shipping_address' => $shipTo,
                'items_count' => $items->count(),
                'shipping_methods' => $shippingMethods,
            ], 'Buyer shipping methods and rates retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('ShipmentController buyerShippingMethods failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Available Shipping Options (Seller — product create/edit form)
     * GET /api/shipping-options
     *
     * Fetches shipping rules from ShipStation API Portal.
     * Falls back to configured rules if live fetch fails.
     */
    public function availableOptions(Request $request): JsonResponse
    {
        try {
            $client = app(\App\Services\ShipEngineClient::class);
            $rules = $client->getShippingRules();

            if ($request->boolean('include_store') || $request->boolean('all') || $request->input('type') === 'all') {
                $nativeRules = $client->getNativeStoreShippingRules();
                $existingKeys = array_column($rules, 'key');
                foreach ($nativeRules as $nRule) {
                    if (! in_array($nRule['key'], $existingKeys, true)) {
                        $rules[] = $nRule;
                    }
                }
            }

            return ApiResponse::success(['shipping_options' => $rules], 'Available shipping options retrieved.');
        } catch (ShipEngineAuthenticationException $e) {
            Log::error('ShipEngine availableOptions auth failed: ' . $e->getMessage());
            return ApiResponse::error('Shipping service is not configured.', 503);
        } catch (Throwable $e) {
            Log::error('availableOptions failed: ' . $e->getMessage());
            return $this->errorResponse($e);
        }
    }

    /**
     * Buyer: Carrier Rates for a Specific Shipping Option (lazy load)
     * GET /api/cart/shipping-methods/{option}/rates
     *
     * Called when buyer clicks a shipping method tile in the cart detail page.
     * Returns only the carrier rates that belong to the selected option key.
     *
     * Query params:
     *   address_id     int     — saved address ID
     *   product_id     int     — single product (optional, defaults to full cart)
     *   cart_item_ids  array   — subset of cart items
     */
    public function ratesForOption(Request $request, string $option): JsonResponse
    {
        try {
            $canonical = ShippingOptionDTO::normalizeKey($option);
            if (! $canonical) {
                return ApiResponse::error('Invalid shipping option key.', 422);
            }

            // Reuse buyerShippingMethods with the option filter applied
            $request->merge(['shipping_option' => $canonical]);
            $response = $this->buyerShippingMethods($request);
            $data     = json_decode($response->getContent(), true);

            // Extract only the matching method
            $methods = $data['data']['shipping_methods'] ?? [];
            $matched = array_values(array_filter($methods, fn ($m) => ($m['key'] ?? '') === $canonical));

            return ApiResponse::success([
                'option_key'         => $canonical,
                'has_shipping_address' => $data['data']['has_shipping_address'] ?? false,
                'rates'              => $matched[0]['rates'] ?? [],
                'rates_count'        => $matched[0]['rates_count'] ?? 0,
                'starting_from'      => $matched[0]['starting_from'] ?? null,
            ], 'Carrier rates for option retrieved.');
        } catch (Throwable $e) {
            Log::error('ShipmentController ratesForOption failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Create Label (Seller fulfillment flow)
     * POST /api/shipengine/create-label
     *
     * Fulfills the order strictly using the rate_id chosen and paid for by the buyer at checkout.
     * Sellers must NEVER select or override rates during fulfillment.
     */
    public function createLabel(Request $request): JsonResponse
    {
        try {
            $user = $request->user() ?? Auth::guard('jwt')->user();
            if (! $user) {
                return ApiResponse::error('Unauthenticated.', 401);
            }
            $validated = $request->validate([
                'order_id' => ['required', 'integer', 'exists:orders,id'],
                'label_format' => ['nullable', 'string', 'in:pdf,zpl,png'],
                'label_layout' => ['nullable', 'string', 'in:4x6,letter'],
                'test_label' => ['nullable', 'boolean'],
            ]);

            $order = Order::with(['items.product', 'seller'])->findOrFail((int) $validated['order_id']);
            $this->authorizeSellerOrAdmin($user, $order);

            $sellerId = (int) ($order->seller_id ?: ($user->profile?->id ?? $user->id));

            $shipment = $this->service->create($order, $sellerId);

            $shipment = $this->service->purchaseLabel(
                $shipment,
                $sellerId,
                $order->selected_rate_id,
                $order->selected_carrier_id,
                $order->selected_service_code,
                $validated['label_format'] ?? 'pdf',
                $validated['label_layout'] ?? '4x6',
                $request->boolean('test_label', false)
            );

            return ApiResponse::success(
                ['label' => new ShipmentResource($shipment)],
                'Shipping label created successfully.',
                200
            );
        } catch (Throwable $e) {
            Log::error('ShipEngine create-label failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Create Shipment
     */
    public function store(StoreShipmentRequest $request): JsonResponse
    {
        try {
            $profile = $this->profile($request);
            $order = Order::findOrFail((int) $request->validated('order_id'));

            $shipment = $this->service->create($order, (int) $profile->id);

            return ApiResponse::success(['shipment' => new ShipmentResource($shipment)], 'Shipment created.', 200);
        } catch (Throwable $e) {
            Log::error('Shipment create failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Purchase Shipping Label
     */
    public function purchaseLabel(PurchaseShippingLabelRequest $request, Shipment $shipment): JsonResponse
    {
        try {
            $this->resolveUser($request);
            $this->authorize('purchaseLabel', $shipment);

            $profile = $this->profile($request);
            $validated = $request->validated();

            $hadLabel = $shipment->hasActiveLabel();
            $shipment = $this->service->purchaseLabel(
                $shipment,
                (int) $profile->id,
                $validated['rate_id'] ?? null,
                $validated['carrier_id'] ?? null,
                $validated['service_code'] ?? null,
                $validated['label_format'] ?? 'pdf',
                $validated['label_layout'] ?? '4x6',
                $validated['test_label'] ?? false,
            );

            return ApiResponse::success(
                ['shipment' => new ShipmentResource($shipment)],
                $hadLabel ? 'Shipping label already exists.' : 'Shipping label created.',
                200
            );
        } catch (Throwable $e) {
            Log::error('Shipping label purchase failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Void Shipping Label
     * POST /api/shipping/void
     */
    public function voidLabel(Request $request, ?Shipment $shipment = null): JsonResponse
    {
        try {
            $user = $request->user() ?? Auth::guard('jwt')->user();
            if (! $user) {
                return ApiResponse::error('Unauthenticated.', 401);
            }
            if (! $shipment) {
                $labelId = (int) $request->input('label_id');
                if (! $labelId) {
                    return ApiResponse::error('label_id is required.', 422);
                }
                $shipment = Shipment::find($labelId);
                if (! $shipment) {
                    return ApiResponse::error('Label not found.', 404);
                }
            }

            $profile = $user->profile;
            if (! $profile) {
                $profile = Profile::firstOrCreate(
                    ['user_id' => $user->id],
                    ['email' => $user->email, 'full_name' => $user->name, 'username' => 'user_'.$user->id]
                );
            }

            $shipment = $this->service->voidLabel($shipment, (int) $profile->id);

            return ApiResponse::success(['label' => new ShipmentResource($shipment)], 'Shipping label voided.');
        } catch (ShipEngineAuthenticationException $e) {
            Log::warning('ShipEngine authentication failed during label void, performing local void: '.$e->getMessage());

            $shipment->update([
                'voided' => true,
                'voided_at' => now(),
                'status' => ShipmentStatus::Voided->value,
                'error_message' => null,
            ]);

            return ApiResponse::success(['label' => new ShipmentResource($shipment->fresh())], 'Shipping label voided.');
        } catch (Throwable $e) {
            Log::error('Shipping label void failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Get Live Tracking Information
     * GET /api/shipping/shipments/{shipment}/tracking
     *
     * IMPORTANT: Never pass tracking_number with a leading `#` in the URL.
     * `#` is the URL fragment delimiter — it is stripped by HTTP clients
     * before the request reaches the server. Encode it as `%23` on the
     * Flutter side: Uri.encodeComponent('#TRN-63623432') → %23TRN-63623432
     */
    public function tracking(Request $request, ?Shipment $shipment = null): JsonResponse
    {
        if (! $shipment) {
            $labelId = (int) $request->input('label_id', $request->query('label_id'));
            if (! $labelId) {
                return ApiResponse::error('label_id is required.', 422);
            }
            $shipment = Shipment::find($labelId);
            if (! $shipment) {
                return ApiResponse::error('Label not found.', 404);
            }
        }

        Log::debug('ShipmentController@tracking', [
            'shipment_id' => $shipment->id,
            'query_string' => $request->server('QUERY_STRING'),
            'request_all' => $request->all(),
            'tracking_number' => $request->query('tracking_number'),
            'full_url' => $request->fullUrl(),
        ]);

        try {
            $user = $request->user() ?? Auth::guard('jwt')->user();
            if (! $user) {
                return ApiResponse::error('Unauthenticated.', 401);
            }

            $trackingNumber = $request->query('tracking_number') ?? $shipment->tracking_number;

            if (empty($trackingNumber)) {
                return ApiResponse::error(
                    'Tracking number is required or improperly formatted. Ensure the tracking_number query parameter is URL-encoded (e.g. %23 instead of #).',
                    422
                );
            }

            $profileId = $user->profile ? (int) $user->profile->id : (int) $user->id;
            $carrierCode = $request->query('carrier_code');
            $data = $this->service->tracking($shipment, $profileId, $carrierCode);

            return ApiResponse::success($data, 'Tracking information retrieved.');
        } catch (Throwable $e) {
            Log::error('Shipment tracking failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * List Connected Carriers
     * GET /api/shipping/carriers
     *
     * Accessible by both Admin and Seller roles to view active connected carriers
     * for generating fulfillment labels.
     */
    public function carriers(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);

            $carriers = $this->service->carriers();

            return ApiResponse::success(['carriers' => $carriers], 'Carriers retrieved.');
        } catch (ShipEngineAuthenticationException $e) {
            Log::error('ShipEngine carriers: API key invalid or not configured — '.$e->getMessage());

            return ApiResponse::error('Shipping service is not configured. Please contact support.', 503);
        } catch (Throwable $e) {
            Log::error('ShipEngine carriers failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Create Return Label
     * POST /api/shipping/return-label
     */
    public function createReturnLabel(Request $request, ?Shipment $shipment = null): JsonResponse
    {
        try {
            $user = $request->user() ?? Auth::guard('jwt')->user();
            if (! $user) {
                return ApiResponse::error('Unauthenticated.', 401);
            }
            if (! $shipment) {
                $labelId = (int) $request->input('label_id');
                if (! $labelId) {
                    return ApiResponse::error('label_id is required.', 422);
                }
                $shipment = Shipment::find($labelId);
                if (! $shipment) {
                    return ApiResponse::error('Label not found.', 404);
                }
            }

            $validated = $request->validate([
                'label_format' => ['nullable', 'string', 'in:pdf,zpl,png'],
                'label_layout' => ['nullable', 'string', 'in:4x6,letter'],
            ]);

            $profile = $user->profile ?? Profile::firstOrCreate(
                ['user_id' => $user->id],
                ['email' => $user->email, 'full_name' => $user->name, 'username' => 'user_'.$user->id]
            );
            $returnLabel = $this->service->createReturnLabel(
                $shipment,
                (int) $profile->id,
                $validated['label_format'] ?? 'pdf',
                $validated['label_layout'] ?? '4x6',
            );

            return ApiResponse::success(['return_label' => $returnLabel], 'Return label created.', 201);
        } catch (Throwable $e) {
            Log::error('Return label creation failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Map errors to safe responses: policy denials become 403 (or the
     * status the policy attached — view/track use 404 to avoid leaking
     * order/shipment existence, i.e. IDOR). Other exceptions keep their
     * code or fall back to 500.
     */
    protected function errorResponse(Throwable $e): JsonResponse
    {
        if ($e instanceof AuthorizationException) {
            $code = $e->hasStatus() ? (int) $e->status() : 403;

            return ApiResponse::error($e->getMessage(), $code);
        }

        return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
    }

    /**
     * Authorize that the authenticated user is the seller who owns the order/items
     * or an admin. Explicitly denies buyer access (403 Forbidden).
     *
     * @throws \Exception
     */
    protected function authorizeSellerOrAdmin(User $user, Order $order): void
    {
        $userId = (int) $user->id;
        $profileId = $user->profile ? (int) $user->profile->id : null;

        // 1. Admin: Allow access (Spatie role or users.role column)
        $isAdmin = (method_exists($user, 'hasRole') && $user->hasRole('admin'))
            || ($user->role === 'admin');

        if ($isAdmin) {
            return;
        }

        // 2. Seller check:
        // $order->seller_id === $user->id OR $order->items()->where('seller_id', $user->id)->exists()
        $isSeller = (int) $order->seller_id === $userId
            || ($profileId !== null && (int) $order->seller_id === $profileId);

        if (! $isSeller) {
            // Check if seller_id column exists on order_items table
            if (Schema::hasColumn('order_items', 'seller_id')) {
                $isSeller = $order->items()->where(function ($q) use ($userId, $profileId) {
                    $q->where('seller_id', $userId);
                    if ($profileId !== null) {
                        $q->orWhere('seller_id', $profileId);
                    }
                })->exists();
            }

            // Check via order items' related product seller_id
            if (! $isSeller) {
                $isSeller = $order->items()->whereHas('product', function ($q) use ($userId, $profileId) {
                    $q->where(function ($sub) use ($userId, $profileId) {
                        $sub->where('seller_id', $userId);
                        if ($profileId !== null) {
                            $sub->orWhere('seller_id', $profileId);
                        }
                    });
                })->exists();
            }

            // Fallback for loaded collection
            if (! $isSeller && $order->relationLoaded('items')) {
                $isSeller = $order->items->contains(function ($item) use ($userId, $profileId) {
                    return (isset($item->seller_id) && ((int) $item->seller_id === $userId || ($profileId !== null && (int) $item->seller_id === $profileId)))
                        || ($item->product && ((int) $item->product->seller_id === $userId || ($profileId !== null && (int) $item->product->seller_id === $profileId)));
                });
            }
        }

        if ($isSeller) {
            return;
        }

        // 3. Buyer check: Allow buyers to view rates for their own order (buyer selects rate at checkout)
        $isBuyer = (int) $order->buyer_id === $userId
            || ($profileId !== null && (int) $order->buyer_id === $profileId);

        if ($isBuyer) {
            return;
        }

        throw new \Exception('Access denied. Only the buyer, seller or admin of this order can view shipping rates.', 403);
    }

    /**
     * Authorize user access to a shipment for viewing/downloading labels.
     * Allows direct browser download/preview if shipment has an active label.
     */
    protected function authorizeShipmentAccess(Request $request, Shipment $shipment): void
    {
        try {
            $this->resolveUser($request);
            $this->authorize('view', $shipment);
        } catch (Throwable $e) {
            // If caller explicitly requested JSON and is NOT requesting direct PDF streaming via query params, enforce auth
            if ($request->expectsJson() && ! $request->has('download') && ! $request->has('preview') && ! $request->has('mode')) {
                throw $e;
            }

            // For browser-initiated download/preview streams, permit if active label exists
            if (! $shipment->hasActiveLabel()) {
                throw $e;
            }
        }
    }

    /**
     * Resolve the authenticated user supporting JWT bearer tokens via Authorization header,
     * token cookie, query string token, or session guards.
     */
    protected function resolveUser(?Request $request = null): User
    {
        $request = $request ?? request();

        $user = auth()->user()
            ?? $request->user()
            ?? Auth::guard('jwt')->user()
            ?? Auth::guard('api')->user()
            ?? Auth::guard('web')->user();

        if (! $user && $request) {
            $token = $request->bearerToken();

            if (! $token) {
                $rawToken = $request->cookie('token')
                    ?? $request->cookies->get('token')
                    ?? ($_COOKIE['token'] ?? null)
                    ?? $request->query('token');

                if (! $rawToken && $request->headers->has('cookie')) {
                    $cookieHeader = (string) $request->header('cookie');
                    if (preg_match('/(?:^|;\s*)token=([^;]+)/', $cookieHeader, $matches)) {
                        $rawToken = urldecode(trim($matches[1], "\"'\t\n\r\0\x0B "));
                    }
                }

                if ($rawToken) {
                    $rawToken = trim((string) $rawToken, "\"'\t\n\r\0\x0B ");
                    $token = str_starts_with($rawToken, 'Bearer ') ? substr($rawToken, 7) : $rawToken;
                }
            }

            if ($token) {
                try {
                    $cleanToken = str_starts_with($token, 'Bearer ') ? substr($token, 7) : $token;
                    $request->headers->set('Authorization', 'Bearer '.$cleanToken);
                    $request->server->set('HTTP_AUTHORIZATION', 'Bearer '.$cleanToken);

                    $guard = Auth::guard('jwt');
                    $guard->setRequest($request);
                    $user = $guard->user();

                    if (! $user) {
                        $jwt = app('tymon.jwt');
                        $jwt->setToken($cleanToken);
                        if ($payload = $jwt->check(true)) {
                            $sub = $payload['sub'] ?? ($payload->get('sub') ?? null);
                            if ($sub) {
                                $user = User::find($sub);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('JWT token resolution in ShipmentController failed: '.$e->getMessage());
                }
            }
        }

        if (! $user) {
            throw new \Exception('Access denied.', 401);
        }

        Auth::setUser($user);
        Auth::guard('jwt')->setUser($user);
        $request->setUserResolver(fn () => $user);

        return $user;
    }

    protected function profile(?Request $request = null): Profile
    {
        $user = $this->resolveUser($request);
        $profile = $user->profile;
        if (! $profile) {
            $profile = Profile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'email' => $user->email,
                    'full_name' => $user->name,
                    'username' => 'user_'.$user->id,
                ]
            );
        }

        return $profile;
    }
}
