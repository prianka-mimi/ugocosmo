<?php

namespace App\Http\Controllers;

use App\DTOs\ShippingOptionDTO;
use App\Models\Product;
use App\Models\SavedAddress;
use App\Services\ShipmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(protected ShipmentService $service) {}

    /**
     * GET /api/checkout/{productId}
     *
     * Returns the shipping options the seller has enabled for this product.
     * Used to render the shipping method tiles on the checkout page.
     * Tiles are disabled until an address is selected (Flutter handles UI state).
     */
    public function shippingOptions(Request $request, int $productId): JsonResponse
    {
        try {
            $product = Product::with(['seller.user'])->findOrFail($productId);

            $options = array_map(fn ($opt) => array_merge(['id' => $opt->key], $opt->toArray()), $product->getEnabledShippingOptions());

            return ApiResponse::success([
                'product_id'       => $product->id,
                'shipping_options' => $options,
            ], 'Shipping options retrieved.');
        } catch (Throwable $e) {
            Log::error('CheckoutController shippingOptions failed: ' . $e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * GET /api/checkout/{productId}/{shippingMethodId}
     *
     * Returns the available carriers and rates for the selected shipping method.
     * address_id query param is required to calculate live rates.
     *
     * Query params:
     *   address_id  int  — saved address ID
     *   quantity    int  — product quantity (default 1)
     */
    public function carriersAndRates(Request $request, int $productId, string $shippingMethodId): JsonResponse
    {
        try {
            $canonical = ShippingOptionDTO::normalizeKey($shippingMethodId);
            if (! $canonical) {
                return ApiResponse::error('Invalid shipping method.', 422);
            }

            $product = Product::with(['seller.user'])->findOrFail($productId);

            if (! $product->supportsShippingOption($canonical)) {
                return ApiResponse::error('This shipping method is not available for this product.', 422);
            }

            $user      = Auth::guard('jwt')->user();
            $profileId = $user->profile ? (int) $user->profile->id : (int) $user->id;

            // Resolve address
            $shipTo = null;
            if ($request->filled('address_id')) {
                $saved = SavedAddress::where('id', (int) $request->input('address_id'))
                    ->where('profile_id', $profileId)
                    ->first();
                if ($saved) {
                    $shipTo = [
                        'name'        => $saved->name ?: ($user->profile?->full_name ?: $user->name),
                        'line1'       => $saved->address_line1,
                        'line2'       => $saved->address_line2,
                        'city'        => $saved->city_locality,
                        'state'       => $saved->state_province,
                        'postal_code' => $saved->postal_code,
                        'country'     => $saved->country_code ?: 'US',
                        'phone'       => $saved->phone,
                    ];
                }
            }

            if (! $shipTo) {
                $default = SavedAddress::where('profile_id', $profileId)->orderByDesc('is_default')->first();
                if ($default) {
                    $shipTo = [
                        'name'        => $default->name ?: ($user->profile?->full_name ?: $user->name),
                        'line1'       => $default->address_line1,
                        'line2'       => $default->address_line2,
                        'city'        => $default->city_locality,
                        'state'       => $default->state_province,
                        'postal_code' => $default->postal_code,
                        'country'     => $default->country_code ?: 'US',
                        'phone'       => $default->phone,
                    ];
                }
            }

            if (! $shipTo || empty($shipTo['line1'])) {
                return ApiResponse::error('No shipping address found. Please add an address first.', 422);
            }

            $qty   = max(1, (int) $request->input('quantity', 1));
            $items = collect([(object) [
                'product'    => $product,
                'product_id' => $product->id,
                'quantity'   => $qty,
            ]]);

            // AliExpress — fixed rate, no ShipEngine call
            $isAliExpress = strtolower((string) $product->source) === 'aliexpress'
                && ! empty($product->aliexpress_product_id);
            if ($isAliExpress) {
                $cost = (float) ($product->aliexpress_shipping_cost ?: ($product->shipping_cost ?? 0.0));
                $eta  = $product->estimated_delivery_time ?: '10-15 business days';
                $rates = [[
                    'rate_id'               => 'ae-fixed-rate-' . $product->id,
                    'carrier_id'            => 'aliexpress_standard',
                    'carrier_friendly_name' => 'Standard Global Logistics',
                    'service_code'          => 'aliexpress_standard',
                    'service_name'          => 'Standard Tracked Shipping',
                    'shipping_method'       => $canonical,
                    'shipping_amount'       => $cost,
                    'currency'              => 'USD',
                    'delivery_days'         => 14,
                    'estimated_delivery'    => $eta,
                    'is_aliexpress'         => true,
                    'trackable'             => true,
                ]];

                $this->cacheRates($profileId, $rates);

                return ApiResponse::success([
                    'product_id'      => $product->id,
                    'shipping_method' => $canonical,
                    'address_used'    => $shipTo,
                    'rates'           => $rates,
                    'rates_count'     => count($rates),
                    'starting_from'   => $cost,
                ], 'Carriers and rates retrieved.');
            }

            // Direct options (Pickups, Free Shipping, Local Delivery) — return directly without calling live courier APIs
            $isPickup = str_ends_with($canonical, '_pickup') || in_array($canonical, [
                ShippingOptionDTO::LOCAL_PICKUP,
                ShippingOptionDTO::MULTI_ITEM_PICKUP,
                ShippingOptionDTO::HEAVY_ITEM_PICKUP,
            ], true);

            if ($isPickup || in_array($canonical, [ShippingOptionDTO::FREE_SHIPPING, ShippingOptionDTO::LOCAL_SHIPPING], true)) {
                $cost = $product->getShippingOptionCost($canonical);
                $dto  = ShippingOptionDTO::make($canonical, $cost);
                $rates = [[
                    'rate_id'               => 'direct-rate-' . $canonical . '-' . $product->id,
                    'carrier_id'            => $isPickup ? 'local_pickup' : 'direct_delivery',
                    'carrier_friendly_name' => $dto->name,
                    'service_code'          => $canonical,
                    'service_name'          => $dto->name,
                    'shipping_method'       => $canonical,
                    'shipping_amount'       => round($dto->cost, 2),
                    'currency'              => 'USD',
                    'delivery_days'         => $isPickup ? 1 : ($canonical === 'free_shipping' ? 7 : 2),
                    'estimated_delivery'    => $dto->estimatedDelivery,
                    'shipping_option_key'   => $canonical,
                    'trackable'             => ! $isPickup,
                ]];

                $this->cacheRates($profileId, $rates);

                return ApiResponse::success([
                    'product_id'      => $product->id,
                    'shipping_method' => $canonical,
                    'address_used'    => $shipTo,
                    'rates'           => $rates,
                    'rates_count'     => count($rates),
                    'starting_from'   => round((float) $rates[0]['shipping_amount'], 2),
                ], 'Carriers and rates retrieved.');
            }

            // Live ShipEngine rates — skip if product uses manual shipping strategy
            if ($product->shipping_strategy === 'manual') {
                $dto     = ShippingOptionDTO::make($canonical, $product->getShippingOptionCost($canonical));
                $rates[] = [
                    'rate_id'               => 'std-rate-' . $canonical,
                    'carrier_id'            => 'standard_carrier',
                    'carrier_friendly_name' => $dto->name,
                    'service_code'          => $canonical,
                    'service_name'          => $dto->name,
                    'shipping_amount'       => round($dto->cost, 2),
                    'currency'              => 'USD',
                    'delivery_days'         => $canonical === 'express_shipping' ? 2 : ($canonical === 'international_shipping' ? 14 : 5),
                    'estimated_delivery'    => $dto->estimatedDelivery,
                    'shipping_option_key'   => $canonical,
                    'trackable'             => true,
                ];

                $this->cacheRates($profileId, $rates);

                return ApiResponse::success([
                    'product_id'      => $product->id,
                    'shipping_method' => $canonical,
                    'address_used'    => $shipTo,
                    'rates'           => $rates,
                    'rates_count'     => count($rates),
                    'starting_from'   => round((float) $rates[0]['shipping_amount'], 2),
                ], 'Carriers and rates retrieved.');
            }

            // Detect cross-border (international) shipment accurately
            $originCountry = null;
            if (! empty($product->ship_from_address)) {
                $parsedOrigin = $this->service->parseAddressData($product->ship_from_address, 'Seller Warehouse', $product->seller?->user?->country);
                $originCountry = $parsedOrigin?->countryCode;
            }
            if (! $originCountry && $product->seller && $product->seller->user) {
                try {
                    $originCountry = $product->seller->user->country ? \App\DTOs\ShipEngine\ShipEngineAddress::normalizeCountryCode($product->seller->user->country) : null;
                } catch (\Throwable) {
                    $originCountry = null;
                }
            }

            $rawDestCountry = (string) ($shipTo['country'] ?? $shipTo['country_code'] ?? 'US');
            try {
                $destCountry = \App\DTOs\ShipEngine\ShipEngineAddress::normalizeCountryCode($rawDestCountry);
            } catch (\Throwable) {
                $destCountry = strtoupper(strlen($rawDestCountry) === 2 ? $rawDestCountry : 'US');
            }

            $isCrossBorder = ($originCountry !== null && $destCountry !== null && $originCountry !== $destCountry);

            $allRates = $this->service->calculateRatesForPayload($items, $shipTo);

            // Tag each rate with option key and filter STRICTLY to the selected method
            $rates = [];
            foreach ($allRates as $r) {
                $serviceText = strtolower(implode(' ', array_filter([
                    (string) ($r['service_code'] ?? ''),
                    (string) ($r['service_type'] ?? ''),
                    (string) ($r['service_name'] ?? ''),
                    (string) ($r['carrier_friendly_name'] ?? ''),
                ])));

                $isIntl = str_contains($serviceText, 'international')
                    || str_contains($serviceText, 'intl')
                    || str_contains($serviceText, 'worldwide')
                    || str_contains($serviceText, 'global');

                // For cross-border shipments, domestic-only carrier rates (e.g. Royal Mail 48, USPS Ground) are invalid
                if ($isCrossBorder && ! $isIntl) {
                    continue;
                }

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
                    || str_contains($serviceText, 'expedited')
                );

                if ($isExpress) {
                    $optKey = 'express_shipping';
                } elseif ($isIntl || $isCrossBorder) {
                    $optKey = ($canonical === 'standard_shipping' && ! $product->supportsShippingOption('international_shipping'))
                        ? 'standard_shipping'
                        : 'international_shipping';
                } else {
                    $optKey = 'standard_shipping';
                }

                if ($optKey === 'international_shipping' && in_array($r['service_type'] ?? '', ['Standard Shipping', 'Standard Delivery'], true)) {
                    $r['service_type'] = 'International Delivery';
                }
                $r['service_name']        = $r['service_name'] ?? ($r['service_type'] ?? ($r['carrier_friendly_name'] ?? ($optKey === 'international_shipping' ? 'International Delivery' : 'Standard Delivery')));
                if ($optKey === 'international_shipping' && in_array($r['service_name'], ['Standard Shipping', 'Standard Delivery'], true)) {
                    $r['service_name'] = 'International Delivery';
                }
                $r['shipping_option_key'] = $optKey;

                // Strict filter: only include rates that match the requested canonical method
                if ($optKey === $canonical) {
                    $rates[] = $r;
                }
            }

            // Fallback: If live carrier did not return a rate specifically for this enabled method,
            // provide a method-specific rate using the seller's configured tier or DTO estimate
            if (empty($rates)) {
                $fallbackCost = $product->getShippingOptionCost($isCrossBorder ? 'international_shipping' : $canonical);
                if ($isCrossBorder && $fallbackCost <= 0) {
                    $fallbackCost = $canonical === 'express_shipping' ? 49.99 : 24.99;
                }

                $dto = ShippingOptionDTO::make($canonical, $fallbackCost);
                $carrierName = $isCrossBorder ? ($canonical === 'express_shipping' ? 'DHL Express Worldwide' : 'FedEx International Economy') : 'Standard Delivery';
                $deliveryDays = $isCrossBorder ? ($canonical === 'express_shipping' ? 4 : 10) : ($canonical === 'express_shipping' ? 2 : 5);
                $estimatedDelivery = $isCrossBorder ? ($canonical === 'express_shipping' ? '3-5 business days' : '7-14 business days') : $dto->estimatedDelivery;

                $rates[] = [
                    'rate_id'               => 'est-rate-' . $canonical . '-' . $product->id,
                    'carrier_id'            => $isCrossBorder ? 'intl_carrier' : 'standard_carrier',
                    'carrier_friendly_name' => $carrierName,
                    'service_code'          => $canonical,
                    'service_name'          => $isCrossBorder ? "International {$dto->name}" : $dto->name,
                    'shipping_amount'       => round(max(0.00, $fallbackCost), 2),
                    'currency'              => 'USD',
                    'delivery_days'         => $deliveryDays,
                    'estimated_delivery'    => $estimatedDelivery,
                    'shipping_option_key'   => $canonical,
                    'trackable'             => true,
                ];
            }

            $startingFrom = min(array_column($rates, 'shipping_amount'));

            $this->cacheRates($profileId, $rates);

            return ApiResponse::success([
                'product_id'      => $product->id,
                'shipping_method' => $canonical,
                'address_used'    => $shipTo,
                'rates'           => $rates,
                'rates_count'     => count($rates),
                'starting_from'   => round((float) $startingFrom, 2),
            ], 'Carriers and rates retrieved.');
        } catch (Throwable $e) {
            Log::error('CheckoutController carriersAndRates failed: ' . $e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    private function cacheRates(int $profileId, array $rates): void
    {
        $key    = "checkout:rates:user:{$profileId}";
        $cached = Cache::get($key, []);
        foreach ($rates as $r) {
            if (! empty($r['rate_id']))    $cached[$r['rate_id']]    = $r;
            if (! empty($r['carrier_id'])) $cached[$r['carrier_id']] = $r;
        }
        Cache::put($key, $cached, now()->addHours(1));
    }

    public static function resolveRateSnapshot(int $profileId, string $rateId): ?array
    {
        $cached = Cache::get("checkout:rates:user:{$profileId}", []);
        return $cached[$rateId] ?? null;
    }
}
