<?php

namespace App\Services;

use App\Actions\ReleaseEscrowFundsAction;
use App\DTOs\ShipEngine\ShipEngineAddress;
use App\DTOs\ShipEngine\ShipEnginePackage;
use App\DTOs\ShippingOptionDTO;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shipping workflow orchestration service for ShipEngine.
 *
 * Owns the business logic, state transitions, idempotency guarantees,
 * and database persistence. Low-level HTTP transport is delegated to
 * App\Services\ShipEngineClient.
 */
class ShipEngineService
{
    public function __construct(
        protected ShipEngineClient $client,
    ) {}

    // ─── Queries ────────────────────────────────────────────────────

    public function forUser(int $profileId, int $perPage = 20): LengthAwarePaginator
    {
        return Shipment::whereHas('order', function ($query) use ($profileId) {
            $query->where('buyer_id', $profileId)
                ->orWhere('seller_id', $profileId);
        })
            ->with('order:id,buyer_id,seller_id,status,tracking_number')
            ->latest()
            ->paginate($perPage);
    }

    public function show(Shipment $shipment, int $profileId): Shipment
    {
        $this->assertAccess($shipment, $profileId);

        return $shipment->load('order:id,buyer_id,seller_id,status,tracking_number');
    }

    /**
     * Validate a shipping address through ShipEngine without modifying the
     * customer's stored address.
     *
     * @param  array<string, mixed>|null  $addressOverride
     * @return array<string, mixed> normalized validation result
     */
    public function validateAddress(Shipment $shipment, int $profileId, ?array $addressOverride = null): array
    {
        $this->assertAccess($shipment, $profileId);

        $address = $addressOverride ?? $shipment->order->shipping_address;

        if (! is_array($address) || $address === []) {
            throw new \Exception('No shipping address to validate.', 422);
        }

        $payload = [
            'address_line1' => (string) ($address['line1'] ?? $address['address_line1'] ?? ''),
            'address_line2' => isset($address['line2']) ? (string) $address['line2'] : null,
            'city_locality' => (string) ($address['city'] ?? $address['city_locality'] ?? ''),
            'state_province' => isset($address['state']) ? (string) $address['state'] : null,
            'postal_code' => (string) ($address['postal_code'] ?? ''),
            'country_code' => ShipEngineAddress::normalizeCountryCode((string) ($address['country'] ?? '')),
        ];

        $results = $this->client->validateAddress(array_filter($payload, fn ($v) => $v !== null && $v !== ''));
        $result = $results[0] ?? [];

        $status = $result['status'] ?? 'unverified';

        return [
            'status' => $status,
            'is_valid' => $status === 'verified',
            'original_address' => $result['original_address'] ?? $payload,
            'matched_address' => $result['matched_address'] ?? null,
            'messages' => $result['messages'] ?? [],
        ];
    }

    /**
     * Validate a standalone address without requiring a pre-existing shipment or order.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed> normalized validation result
     */
    public function validateStandaloneAddress(array $address): array
    {
        if (empty($address)) {
            throw new \Exception('No shipping address to validate.', 422);
        }

        $payload = [
            'address_line1' => (string) ($address['line1'] ?? $address['address_line1'] ?? $address['street'] ?? ''),
            'address_line2' => isset($address['line2']) ? (string) $address['line2'] : ($address['address_line2'] ?? null),
            'city_locality' => (string) ($address['city'] ?? $address['city_locality'] ?? ''),
            'state_province' => isset($address['state']) ? (string) $address['state'] : ($address['state_province'] ?? null),
            'postal_code' => (string) ($address['postal_code'] ?? $address['zip'] ?? ''),
            'country_code' => ShipEngineAddress::normalizeCountryCode((string) ($address['country'] ?? $address['country_code'] ?? 'US')),
        ];

        $results = $this->client->validateAddress(array_filter($payload, fn ($v) => $v !== null && $v !== ''));
        $result = $results[0] ?? [];

        $status = $result['status'] ?? 'unverified';

        return [
            'status' => $status,
            'is_valid' => $status === 'verified',
            'original_address' => $result['original_address'] ?? $payload,
            'matched_address' => $result['matched_address'] ?? null,
            'messages' => $result['messages'] ?? [],
        ];
    }

    /**
     * List carriers connected to the ShipEngine account (cached for 24h).
     *
     * @return array<int, array<string, mixed>>
     */
    public function carriers(): array
    {
        if ($this->shouldUseMock()) {
            return $this->mockCarriers();
        }

        try {
            return Cache::remember('shipengine:carriers', now()->addDay(), function () {
                $response = $this->client->listCarriers();
                $carriers = $response['carriers'] ?? [];

                if (empty($carriers)) {
                    return $this->fallbackCarriers();
                }

                return array_map(fn (array $carrier) => [
                    'carrier_id' => $carrier['carrier_id'] ?? null,
                    'carrier_code' => $carrier['carrier_code'] ?? null,
                    'friendly_name' => $carrier['friendly_name'] ?? $carrier['nickname'] ?? null,
                    'services' => array_map(fn (array $service) => [
                        'carrier_id' => $service['carrier_id'] ?? null,
                        'code' => $service['service_code'] ?? $service['code'] ?? null,
                        'name' => $service['name'] ?? null,
                    ], $carrier['services'] ?? []),
                ], $carriers);
            });
        } catch (Throwable $e) {
            if (! app()->isProduction() || $this->shouldUseMock()) {
                Log::warning('ShipEngine carriers failed in non-production, falling back to mock carriers: '.$e->getMessage());

                return $this->mockCarriers();
            }

            $fallback = $this->fallbackCarriers();
            if (! empty($fallback)) {
                Log::warning('ShipEngine carriers API failed, using configured fallback carriers: '.$e->getMessage());

                return $fallback;
            }

            throw $e;
        }
    }

    // ─── Rates ──────────────────────────────────────────────────────

    /**
     * Estimate shipping rates for an order (seller).
     *
     * @param  array<string, mixed>  $rateOptions
     * @return array<int, array<string, mixed>>
     */
    public function rates(Order $order, int $sellerId, array $rateOptions = [], ?string $validateAddress = null): array
    {
        $this->assertSeller($order, $sellerId);

        return $this->quoteRates($order, $rateOptions, $validateAddress);
    }

    /**
     * Calculate carrier rates for an order.
     *
     * @param  array<string, mixed>  $rateOptions
     * @return array<int, array<string, mixed>>
     */
    public function calculateRatesForOrder(Order $order, array $rateOptions = [], ?string $validateAddress = null): array
    {
        return $this->quoteRates($order, $rateOptions, $validateAddress);
    }

    /**
     * Rates for the order's buyer at checkout.
     *
     * @param  array<string, mixed>  $rateOptions
     * @return array<int, array<string, mixed>>
     */
    public function ratesForBuyer(Order $order, int $buyerId, array $rateOptions = [], ?string $validateAddress = null): array
    {
        $this->assertBuyer($order, $buyerId);

        return $this->quoteRates($order, $rateOptions, $validateAddress);
    }

    /**
     * Calculate carrier rates for an arbitrary collection of items and destination address.
     * Excludes AliExpress dropshipping products (which use AliExpress logistics).
     *
     * @param  iterable  $items
     * @param  array<string, mixed>  $shipToData
     * @param  array<string, mixed>  $rateOptions
     * @return array<int, array<string, mixed>>
     */
    public function calculateRatesForPayload(
        iterable $items,
        array $shipToData,
        array $rateOptions = [],
        ?string $validateAddress = null
    ): array {
        // 1. Mandatory destination address validation
        if (empty($shipToData)) {
            throw new \Exception('Destination address (ship_to) is required to calculate shipping rates.', 422);
        }

        try {
            $shipTo = ShipEngineAddress::fromOrder($shipToData);
        } catch (\Throwable $e) {
            throw new \Exception('Destination address (ship_to) is incomplete: '.$e->getMessage(), 422);
        }

        // 2. Extract eligible local shippable products
        $localProducts = [];
        foreach ($items as $item) {
            $product = null;
            if ($item instanceof Product) {
                $product = $item;
            } elseif (is_object($item) && isset($item->product)) {
                $product = $item->product;
            } elseif (is_array($item)) {
                if (isset($item['product']) && $item['product'] instanceof Product) {
                    $product = $item['product'];
                } elseif (isset($item['product_id'])) {
                    $product = Product::find($item['product_id']);
                }
            }

            if (! $product) {
                continue;
            }

            // Exclude dropshipped AliExpress items
            if (strtolower((string) $product->source) === 'aliexpress' || ! empty($product->aliexpress_product_id)) {
                continue;
            }

            // Exclude digital products
            $digitalTypes = config('shipping.digital_product_types', ['digital', 'digital_download', 'service', 'event']);
            if (in_array(strtolower((string) $product->product_type), $digitalTypes, true)) {
                continue;
            }

            // Exclude manual shipping strategy products — they use seller-defined fixed costs
            if ($product->shipping_strategy === 'manual') {
                continue;
            }

            $qty = is_object($item) ? (int) ($item->quantity ?? 1) : (int) ($item['quantity'] ?? 1);
            $localProducts[] = ['product' => $product, 'quantity' => max(1, $qty)];
        }

        if (empty($localProducts)) {
            return [];
        }

        // 3. Resolve ship_from address
        $shipFrom = null;
        foreach ($localProducts as $lp) {
            $prod = $lp['product'];
            $sellerCountry = $prod->seller?->user?->country ?? $prod->seller?->country ?? null;

            if (! empty($prod->ship_from_address)) {
                $shipFrom = $this->parseAddressData($prod->ship_from_address, 'Seller Warehouse', $sellerCountry);
                if ($shipFrom !== null) {
                    break;
                }
            }
            if ($prod->seller && $prod->seller->user) {
                $sUser = $prod->seller->user;
                if (! empty($sUser->address_line_1) && ! empty($sUser->city)) {
                    $countryCode = ShipEngineAddress::normalizeCountryCode((string) ($sUser->country ?: ($sellerCountry ?: 'US')));
                    $shipFrom = new ShipEngineAddress(
                        name: $sUser->name ?: ($prod->seller->full_name ?: 'Seller'),
                        addressLine1: (string) $sUser->address_line_1,
                        cityLocality: (string) $sUser->city,
                        postalCode: (string) ($sUser->postal_code ?: '00000'),
                        countryCode: $countryCode,
                        companyName: $prod->seller->full_name ?? null,
                        addressLine2: $sUser->address_line_2 ? (string) $sUser->address_line_2 : null,
                        stateProvince: $countryCode === 'US' ? (ShipEngineAddress::normalizeUsState($sUser->state ?? null) ?? 'TX') : null,
                        phone: $sUser->mobile_number ? (string) $sUser->mobile_number : '+1 555-555-5555',
                        email: $sUser->email ? (string) $sUser->email : null,
                    );
                    break;
                }
            }
        }

        if ($shipFrom === null) {
            $config = config('shipengine.ship_from') ?: (config('services.shipengine.ship_from') ?? []);
            if (is_array($config) && ! empty($config['line1'] ?? $config['address_line1'] ?? null)) {
                try {
                    $shipFrom = ShipEngineAddress::fromConfig($config);
                } catch (\Throwable) {
                    // fall through
                }
            }
        }

        if ($shipFrom === null) {
            $shipFrom = new ShipEngineAddress(
                name: 'Castello Warehouse',
                addressLine1: '100 Main Street',
                cityLocality: 'Austin',
                postalCode: '78701',
                countryCode: 'US',
                stateProvince: 'TX',
            );
        }

        // 4. Build packages
        $config = config('shipengine') ?: (config('services.shipengine') ?? []);
        $packages = [];
        foreach ($localProducts as $lp) {
            $packages[] = ShipEnginePackage::fromProduct($lp['product'], $lp['quantity'], $config);
        }

        if (empty($packages)) {
            return [];
        }

        // 5. Quote rates
        $payload = [
            'validate_address' => $validateAddress ?? 'no_validation',
            'ship_to' => $shipTo->toPayload(),
            'ship_from' => $shipFrom->toPayload(),
            'packages' => array_map(fn (ShipEnginePackage $p) => $p->toPayload(), $packages),
        ];

        // 6. Cross-border / International shipments strictly require customs items for courier quotes
        $isCrossBorder = ($shipFrom->countryCode !== $shipTo->countryCode);
        if ($isCrossBorder) {
            $customsItems = [];
            foreach ($localProducts as $lp) {
                $p = $lp['product'];
                $qty = $lp['quantity'];
                $itemVal = (float) ($p->price ?? 10.00);
                $customsItems[] = [
                    'description' => substr((string) ($p->title ?? 'Merchandise'), 0, 50),
                    'quantity' => max(1, $qty),
                    'value' => [
                        'currency' => strtoupper((string) ($p->currency ?? 'USD')),
                        'amount' => round($itemVal, 2),
                    ],
                    'harmonized_tariff_code' => (string) ($p->ean_upc ?? ''),
                    'country_of_origin' => $shipFrom->countryCode,
                ];
            }

            if (! empty($customsItems)) {
                $payload['customs'] = [
                    'contents' => 'merchandise',
                    'non_delivery' => 'return_to_sender',
                    'customs_items' => $customsItems,
                ];
            }
        }

        // Dynamically populate connected carrier IDs if caller did not supply them
        if (empty($rateOptions['carrier_ids'])) {
            try {
                $carriersData = $this->client->listCarriers();
                $cIds = array_values(array_filter(array_column($carriersData['carriers'] ?? [], 'carrier_id')));
                if (! empty($cIds)) {
                    $rateOptions['carrier_ids'] = $cIds;
                }
            } catch (\Throwable $carrierEx) {
                Log::warning('ShipEngine: unable to load dynamic carrier IDs: '.$carrierEx->getMessage());
            }
        }

        try {
            $response = $this->client->getRates($payload, $rateOptions);
            $rates = $this->normalizeRates($response);
        } catch (\Throwable $e) {
            if (! app()->isProduction() || $this->shouldUseMock()) {
                Log::warning('ShipEngine rate calculation fallback to mock: '.$e->getMessage());
                $currency = $shipTo->countryCode === 'GB' ? 'GBP' : 'USD';
                $isCrossBorder = ($shipFrom->countryCode !== $shipTo->countryCode);

                if ($isCrossBorder) {
                    // Cross-border international shipments: Only offer international carrier rates (FedEx, DHL)
                    $rates = [
                        [
                            'rate_id' => 'se-mock-rate-intl-standard-101',
                            'carrier_id' => 'se-360752',
                            'carrier_code' => 'fedex',
                            'carrier_friendly_name' => 'FedEx International Economy',
                            'service_code' => 'fedex_international_economy',
                            'service_type' => 'International Economy',
                            'shipping_amount' => 24.99,
                            'currency' => $currency,
                            'delivery_days' => 10,
                            'estimated_delivery_date' => now()->addDays(10)->toIso8601String(),
                            'trackable' => true,
                            'rate_attributes' => [],
                            'warning_messages' => [],
                        ],
                        [
                            'rate_id' => 'se-mock-rate-intl-express-202',
                            'carrier_id' => 'se-360753',
                            'carrier_code' => 'dhl_express',
                            'carrier_friendly_name' => 'DHL Express Worldwide',
                            'service_code' => 'dhl_express_worldwide',
                            'service_type' => 'Express Shipping',
                            'shipping_amount' => 49.99,
                            'currency' => $currency,
                            'delivery_days' => 4,
                            'estimated_delivery_date' => now()->addDays(4)->toIso8601String(),
                            'trackable' => true,
                            'rate_attributes' => [],
                            'warning_messages' => [],
                        ],
                        [
                            'rate_id' => 'se-mock-rate-intl-postal-303',
                            'carrier_id' => 'se-360754',
                            'carrier_code' => 'international_post',
                            'carrier_friendly_name' => 'International Priority Airmail',
                            'service_code' => 'international_shipping',
                            'service_type' => 'International Shipping',
                            'shipping_amount' => 19.99,
                            'currency' => $currency,
                            'delivery_days' => 14,
                            'estimated_delivery_date' => now()->addDays(14)->toIso8601String(),
                            'trackable' => true,
                            'rate_attributes' => [],
                            'warning_messages' => [],
                        ],
                    ];
                } else {
                    // Domestic shipments within same country
                    $rates = [
                        [
                            'rate_id' => 'se-mock-rate-standard-101',
                            'carrier_id' => 'se-360751',
                            'carrier_code' => 'royal_mail',
                            'carrier_friendly_name' => 'Royal Mail Tracked 48',
                            'service_code' => 'royal_mail_track_48',
                            'service_type' => 'Standard Shipping',
                            'shipping_amount' => 4.99,
                            'currency' => $currency,
                            'delivery_days' => 3,
                            'estimated_delivery_date' => now()->addDays(3)->toIso8601String(),
                            'trackable' => true,
                            'rate_attributes' => [],
                            'warning_messages' => [],
                        ],
                        [
                            'rate_id' => 'se-mock-rate-express-202',
                            'carrier_id' => 'se-360749',
                            'carrier_code' => 'royal_mail',
                            'carrier_friendly_name' => 'Royal Mail Tracked 24',
                            'service_code' => 'royal_mail_track_24',
                            'service_type' => 'Express Shipping',
                            'shipping_amount' => 8.99,
                            'currency' => $currency,
                            'delivery_days' => 1,
                            'estimated_delivery_date' => now()->addDay()->toIso8601String(),
                            'trackable' => true,
                            'rate_attributes' => [],
                            'warning_messages' => [],
                        ],
                    ];
                }
            } else {
                throw $e;
            }
        }

        // 6. Filter by allowed_shipping_options if configured on products
        $allowedOptions = null;
        foreach ($localProducts as $lp) {
            $opts = $lp['product']->allowed_shipping_options;
            if (! empty($opts) && is_array($opts)) {
                $allowedOptions = $allowedOptions === null ? $opts : array_values(array_intersect($allowedOptions, $opts));
            }
        }

        if (! empty($allowedOptions)) {
            $rates = $this->filterRatesByAllowedOptions($rates, $allowedOptions);
        }

        // 7. Sort cheapest first strictly
        usort($rates, fn ($a, $b) => ($a['shipping_amount'] ?? 0) <=> ($b['shipping_amount'] ?? 0));

        return array_values($rates);
    }

    /**
     * Filter ShipEngine rates by the seller's allowed shipping options.
     *
     * @param  array<int, array<string, mixed>>  $rates
     * @param  array<int, string>  $allowedOptions
     * @return array<int, array<string, mixed>>
     */
    public function filterRatesByAllowedOptions(array $rates, array $allowedOptions): array
    {
        if (empty($allowedOptions) || empty($rates)) {
            return $rates;
        }

        $normalized = array_map(fn ($opt) => ShippingOptionDTO::normalizeKey($opt), $allowedOptions);

        $filtered = array_filter($rates, function (array $rate) use ($normalized) {
            $service = strtolower((string) ($rate['service_code'] ?? $rate['service_type'] ?? ''));

            $isExpress = str_contains($service, 'express')
                || str_contains($service, '2day')
                || str_contains($service, 'overnight')
                || str_contains($service, 'next_day')
                || str_contains($service, 'priority_mail_express');

            $isStandard = ! $isExpress;

            if (in_array(ShippingOptionDTO::EXPRESS_SHIPPING, $normalized, true) && $isExpress) {
                return true;
            }

            if (in_array(ShippingOptionDTO::STANDARD_SHIPPING, $normalized, true) && $isStandard) {
                return true;
            }

            if (in_array(ShippingOptionDTO::FREE_SHIPPING, $normalized, true) && $isStandard) {
                return true;
            }

            if (in_array(ShippingOptionDTO::INTERNATIONAL_SHIPPING, $normalized, true) && str_contains($service, 'international')) {
                return true;
            }

            return false;
        });

        return ! empty($filtered) ? array_values($filtered) : $rates;
    }

    /**
     * Persist the chosen rate or carrier on the order after re-verifying it
     * against ShipEngine quotes inside an atomic transaction.
     *
     * @param  array<string, mixed>  $rateOptions
     * @return array{order: Order, rate: array<string, mixed>, amount_changed: bool}
     */
    public function selectRate(
        Order $order,
        int $userId,
        ?string $rateId = null,
        array $rateOptions = [],
        ?string $carrierId = null,
        ?string $serviceCode = null
    ): array {
        $this->assertBuyerOrSeller($order, $userId);

        $isSellerOrAdmin = $this->isSellerOrAdmin($order, $userId);

        if (! $isSellerOrAdmin && $order->isTerminalForPayment()) {
            throw new \Exception('This order can no longer be modified.', 409);
        }

        if ($isSellerOrAdmin && in_array($order->status, ['delivered', 'completed', 'cancelled'], true)) {
            throw new \Exception("Cannot update shipping rate for an order that is {$order->status}.", 409);
        }

        // Re-quote from ShipEngine (never trust client price)
        $rates = $this->quoteRates($order, $rateOptions, null);

        $rate = null;

        // 1. Match by exact rate_id
        if (! empty($rateId)) {
            foreach ($rates as $candidate) {
                if (($candidate['rate_id'] ?? null) === $rateId) {
                    $rate = $candidate;
                    break;
                }
            }
        }

        // 2. Match by carrier_id if rateId was actually a carrier ID or carrierId was passed
        $targetCarrierId = $carrierId ?: $rateId;
        if ($rate === null && ! empty($targetCarrierId)) {
            foreach ($rates as $candidate) {
                if (($candidate['carrier_id'] ?? null) === $targetCarrierId) {
                    if ($serviceCode === null || ($candidate['service_code'] ?? null) === $serviceCode) {
                        $rate = $candidate;
                        break;
                    }
                }
            }
        }

        // 3. Match by carrier_code
        if ($rate === null && ! empty($rateId)) {
            foreach ($rates as $candidate) {
                if (($candidate['carrier_code'] ?? null) === $rateId) {
                    $rate = $candidate;
                    break;
                }
            }
        }

        // 4. Fallback: match connected carrier from account (e.g. se-360748)
        if ($rate === null && ! empty($targetCarrierId)) {
            $carriers = $this->carriers();
            foreach ($carriers as $c) {
                if (($c['carrier_id'] ?? null) === $targetCarrierId || ($c['carrier_code'] ?? null) === $targetCarrierId) {
                    $matchedService = null;
                    if (! empty($serviceCode)) {
                        foreach ($c['services'] ?? [] as $s) {
                            if (($s['code'] ?? null) === $serviceCode) {
                                $matchedService = $s;
                                break;
                            }
                        }
                    }
                    $selectedService = $matchedService ?: ($c['services'][0] ?? null);

                    $rate = [
                        'rate_id' => 'se-rate-'.$c['carrier_id'].'-'.($selectedService['code'] ?? 'std'),
                        'carrier_id' => $c['carrier_id'],
                        'carrier_code' => $c['carrier_code'],
                        'carrier_friendly_name' => $c['friendly_name'],
                        'service_code' => $selectedService['code'] ?? 'standard',
                        'service_type' => $selectedService['name'] ?? ($c['friendly_name'].' Standard'),
                        'shipping_amount' => (float) ($order->shipping_amount ?? 0),
                        'currency' => strtoupper((string) ($order->shipping_currency ?: $order->currency ?: 'USD')),
                        'delivery_days' => 3,
                        'estimated_delivery_date' => now()->addDays(3)->toIso8601String(),
                        'trackable' => true,
                        'rate_attributes' => [],
                        'warning_messages' => [],
                    ];
                    break;
                }
            }
        }

        // 5. If still null but we have candidate rates and no specific rate was matched, use first candidate
        if ($rate === null && empty($rateId) && ! empty($rates)) {
            $rate = $rates[0];
        }

        if ($rate === null || empty($rate['rate_id'])) {
            throw new \Exception('The selected shipping rate or carrier is no longer available. Refresh the rates and choose again.', 422);
        }

        $amount = (float) ($rate['shipping_amount'] ?? 0);
        $previousAmount = $order->shipping_amount !== null ? (float) $order->shipping_amount : null;

        DB::transaction(function () use ($order, $rate, $amount) {
            $order->update([
                'selected_rate_id' => $rate['rate_id'],
                'selected_carrier_id' => $rate['carrier_id'] ?? null,
                'selected_carrier_code' => $rate['carrier_code'] ?? null,
                'selected_service_code' => $rate['service_code'] ?? null,
                'selected_service_name' => $rate['service_type'] ?? null,
                'shipping_amount' => $amount,
                'shipping_currency' => $rate['currency'] ?? 'USD',
                'selected_rate_snapshot' => $rate,
            ]);
        });

        return [
            'order' => $order->fresh(),
            'rate' => $rate,
            'amount_changed' => $previousAmount === null || abs($previousAmount - $amount) >= 0.01,
        ];
    }

    /**
     * Shared rate quoting for seller and buyer entry points.
     *
     * @param  array<string, mixed>  $rateOptions
     * @return array<int, array<string, mixed>>
     */
    protected function quoteRates(Order $order, array $rateOptions = [], ?string $validateAddress = null): array
    {
        $order->loadMissing('items.product');

        // 1. Orders with no physical shippable items cannot quote carrier rates.
        if (! $order->hasPhysicalItems()) {
            throw new \Exception('This order does not contain physical shippable items.', 422);
        }

        // 2. Check dropshipped vs local/self-created/csv/shipengine eligibility:
        // Allow physical products whose source is 'local', 'self_created', 'csv', or 'shipengine'.
        // Exclude only dropshipped items ('aliexpress') or non-physical items ('service', 'event', 'digital').
        $hasEligibleShippable = $order->items->contains(function ($item) {
            // Exclude non-physical item types (services, event tickets, bookings)
            if (! empty($item->service_id) || ! empty($item->ticket_id) || ! empty($item->booking_id)) {
                return false;
            }

            if (! empty($item->item_type) && strtolower((string) $item->item_type) !== 'product') {
                return false;
            }

            $product = $item->product;
            if (! $product && empty($item->product_id)) {
                return false;
            }

            if ($product) {
                // Exclude digital products
                $digitalTypes = config('shipping.digital_product_types', ['digital', 'digital_download', 'service', 'event']);
                if (in_array(strtolower((string) $product->product_type), $digitalTypes, true)) {
                    return false;
                }

                // Exclude items explicitly flagged with AliExpress product IDs
                if (! empty($product->aliexpress_product_id)) {
                    return false;
                }
            }

            $source = strtolower((string) ($item->source ?? $product?->source ?? 'local'));

            // Exclude dropshipped AliExpress items
            if ($source === 'aliexpress') {
                return false;
            }

            // Allow physical products whose source is local, self_created, csv, or shipengine
            return in_array($source, ['local', 'self_created', 'csv', 'shipengine'], true);
        });

        if (! $hasEligibleShippable) {
            if ($order->isAliExpressFulfilled()) {
                throw new \Exception('Shipping rates for AliExpress orders are managed via AliExpress logistics and cannot use ShipEngine.', 422);
            }
            throw new \Exception('This order does not require ShipEngine shipping rate selection (it is fulfilled externally, digitally, or manually).', 422);
        }

        if ($this->shouldUseMock()) {
            return $this->mockRates($order);
        }

        $payload = $this->buildShipmentPayload($order, $validateAddress);

        try {
            $connectedCarriers = $this->carriers();
            $connectedCarrierIds = array_values(array_filter(
                array_column($connectedCarriers, 'carrier_id')
            ));

            $validServiceCodes = [];
            foreach ($connectedCarriers as $carrier) {
                foreach ($carrier['services'] ?? [] as $service) {
                    $code = (string) ($service['code'] ?? '');
                    if ($code !== '') {
                        $validServiceCodes[$code] = true;
                    }
                }
            }

            $providedCarrierIds = $rateOptions['carrier_ids'] ?? [];

            if (empty($providedCarrierIds)) {
                $rateOptions['carrier_ids'] = $connectedCarrierIds;
            } else {
                $validProvidedIds = array_values(array_filter(
                    $providedCarrierIds,
                    fn ($id) => in_array($id, $connectedCarrierIds, true)
                ));

                $invalidProvidedIds = array_values(array_filter(
                    $providedCarrierIds,
                    fn ($id) => ! in_array($id, $connectedCarrierIds, true)
                ));

                if ($invalidProvidedIds !== []) {
                    throw new \Exception(
                        'The following carrier_ids are not connected to this ShipEngine account: '
                        .implode(', ', $invalidProvidedIds)
                        .'. Connected carriers: '
                        .implode(', ', $connectedCarrierIds)
                        .'. Call GET /api/shipping/carriers to retrieve valid carrier IDs, '
                        .'or omit carrier_ids to automatically use all connected carriers.',
                        400
                    );
                }

                $rateOptions['carrier_ids'] = $validProvidedIds;
            }

            if (! empty($rateOptions['service_codes'] ?? [])) {
                $rateOptions['service_codes'] = array_values(array_filter(
                    $rateOptions['service_codes'],
                    fn ($code) => isset($validServiceCodes[(string) $code])
                ));

                if ($rateOptions['service_codes'] === []) {
                    unset($rateOptions['service_codes']);
                }
            }

            if ($rateOptions['carrier_ids'] === []) {
                throw new \Exception(
                    'No carriers are connected to the ShipEngine account. Connect a carrier before requesting rates.',
                    422
                );
            }

            $cacheKey = 'shipengine:rates:'.$order->id.':'.md5(serialize([$payload, $rateOptions]));

            $rates = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($payload, $rateOptions) {
                $response = $this->client->getRates($payload, $rateOptions);

                Log::debug('ShipEngine raw rates response', [
                    'request_payload' => ['shipment' => $payload, 'rate_options' => $rateOptions],
                    'rate_response' => $response['rate_response'] ?? $response,
                ]);

                return $this->normalizeRates($response);
            });

            $allowedOptions = null;
            foreach ($order->items as $item) {
                if ($item->product && ! empty($item->product->allowed_shipping_options)) {
                    $opts = $item->product->allowed_shipping_options;
                    $allowedOptions = $allowedOptions === null ? $opts : array_values(array_intersect($allowedOptions, $opts));
                }
            }
            if (! empty($allowedOptions)) {
                $rates = $this->filterRatesByAllowedOptions($rates, $allowedOptions);
            }

            return $rates;
        } catch (Throwable $e) {
            if (! app()->isProduction() || $this->shouldUseMock()) {
                Log::warning('ShipEngine rate calculation failed in non-production, returning mock rates fallback: '.$e->getMessage());

                $rates = $this->mockRates($order);
                $allowedOptions = null;
                foreach ($order->items as $item) {
                    if ($item->product && ! empty($item->product->allowed_shipping_options)) {
                        $opts = $item->product->allowed_shipping_options;
                        $allowedOptions = $allowedOptions === null ? $opts : array_values(array_intersect($allowedOptions, $opts));
                    }
                }
                if (! empty($allowedOptions)) {
                    $rates = $this->filterRatesByAllowedOptions($rates, $allowedOptions);
                }

                return $rates;
            }

            throw $e;
        }
    }

    // ─── Shipment ───────────────────────────────────────────────────

    /**
     * Create the local shipment record for an order (idempotent).
     */
    public function create(Order $order, int $sellerId): Shipment
    {
        $this->assertSeller($order, $sellerId);
        $this->assertReadyToShip($order);

        $payload = $this->buildShipmentPayload($order);

        $newShipment = Shipment::create([
            'order_id' => $order->id,
            'seller_id' => (int) $order->seller_id,
            'rate_id' => $order->selected_rate_id,
            'carrier_id' => $order->selected_carrier_id,
            'carrier_code' => $order->selected_carrier_code,
            'service_code' => $order->selected_service_code,
            'status' => ShipmentStatus::Created->value,
            'ship_from_snapshot' => $payload['ship_from'] ?? null,
            'ship_to_snapshot' => $payload['ship_to'] ?? null,
            'package_snapshot' => $payload['packages'] ?? null,
        ]);
        $newShipment->setRelation('order', $order);

        return $newShipment;
    }

    // ─── Label ──────────────────────────────────────────────────────

    /**
     * Purchase a shipping label for a shipment (explicit, authorized, idempotent).
     *
     * @return Shipment the shipment with purchased label metadata recorded
     */
    public function purchaseLabel(
        Shipment $shipment,
        int $sellerId,
        ?string $rateId,
        ?string $carrierId,
        ?string $serviceCode,
        string $labelFormat = 'pdf',
        string $labelLayout = '4x6',
        bool $testLabel = false,
    ): Shipment {
        $order = $shipment->order;
        if (! $order || empty($order->shipping_address)) {
            $order = Order::with(['items.product', 'seller.user'])->find($shipment->order_id);
            $shipment->setRelation('order', $order);
        }
        $this->assertSeller($order, $sellerId);

        // Idempotency: return existing label immediately if already purchased
        if ($shipment->hasActiveLabel()) {
            return $shipment->load('order:id,buyer_id,seller_id,status,tracking_number');
        }

        $this->assertReadyToShip($order);

        $explicitRateId = $rateId !== null;
        $rateId = $rateId ?? $shipment->rate_id ?? $order->selected_rate_id;

        // Atomically claim the shipment to prevent double purchase
        $shipment = $this->claimForPurchase($shipment);

        // Idempotency: return existing label if already purchased
        if ($shipment->hasActiveLabel()) {
            return $shipment->load('order:id,buyer_id,seller_id,status,tracking_number');
        }

        $payload = $this->buildShipmentPayload($order);

        try {
            $response = null;

            if ($this->shouldUseMock()) {
                $response = $this->mockLabelResponse($shipment);
            }

            // Attempt to buy using the saved or provided rate_id first
            if ($response === null && $rateId !== null) {
                if (str_starts_with((string) $rateId, 'se-mock-rate-')) {
                    $response = $this->mockLabelResponse($shipment);
                } else {
                    try {
                        $response = $this->client->createLabelFromRate($rateId, $labelFormat, $labelLayout, $testLabel);
                    } catch (\Exception $e) {
                        // If the rate was automatically fetched and it expired/is invalid, fallback to on-the-fly creation.
                        if (! $explicitRateId && (str_contains(strtolower($e->getMessage()), 'invalid') || str_contains(strtolower($e->getMessage()), 'expired') || str_contains(strtolower($e->getMessage()), 'not found'))) {
                            $rateId = null; // Trigger fallback
                        } else {
                            throw $e;
                        }
                    }
                }
            }

            // Fallback to generating a brand new label on the fly without a rate_id
            if ($response === null && $rateId === null) {
                $carrierId = $carrierId ?? $order->selected_carrier_id;
                $serviceCode = $serviceCode ?? $order->selected_service_code;
                $carrierCode = $order->selected_carrier_code;

                // ShipEngine createLabel API strictly requires carrier_id and service_code.
                // We must resolve carrier_id from carrier_code if it is missing.
                if ($carrierId === null || $serviceCode === null) {
                    [$resolvedCarrierId, $resolvedServiceCode] = $this->resolveCarrierAndServiceForOrder($order, $carrierId, $serviceCode, $carrierCode);
                    $carrierId = $carrierId ?? $resolvedCarrierId;
                    $serviceCode = $serviceCode ?? $resolvedServiceCode;
                }

                if ($carrierId === null && $serviceCode === null) {
                    throw new \Exception('Provide a rate_id, or carrier details and service_code, to purchase a label.', 422);
                }

                $labelRequestPayload = $payload;
                $labelRequestPayload['carrier_id'] = $carrierId;
                $labelRequestPayload['service_code'] = $serviceCode;

                $response = $this->client->createLabel(
                    $labelRequestPayload,
                    $labelFormat,
                    $labelLayout,
                    $testLabel
                );
            }
        } catch (Throwable $e) {
            if (! app()->isProduction() && ($this->shouldUseMock() || $e instanceof ShipEngineAuthenticationException || str_starts_with((string) $rateId, 'se-mock-rate-'))) {
                Log::warning('ShipEngine label purchase failed in non-production, using mock label fallback: '.$e->getMessage());
                $response = $this->mockLabelResponse($shipment);
            } else {
                $shipment->update([
                    'status' => ShipmentStatus::Failed->value,
                    'error_message' => $this->sanitizeError($e),
                ]);
                throw $e;
            }
        }

        $this->persistLabel($shipment, $response);

        return $shipment->fresh()->load('order:id,buyer_id,seller_id,status,tracking_number');
    }

    /**
     * Atomically claim a shipment for label purchase.
     */
    protected function claimForPurchase(Shipment $shipment): Shipment
    {
        return DB::transaction(function () use ($shipment) {
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new \Exception('Shipment not found.', 404);
            }

            if ($locked->hasActiveLabel()) {
                return $locked;
            }

            if ($locked->status === ShipmentStatus::Purchasing->value) {
                throw new \Exception('A label purchase for this shipment is already in progress.', 409);
            }

            $locked->update(['status' => ShipmentStatus::Purchasing->value]);

            return $locked;
        });
    }

    /**
     * Void the purchased label (idempotent).
     */
    public function voidLabel(Shipment $shipment, int $sellerId): Shipment
    {
        $this->assertBuyerOrSeller($shipment->order, $sellerId);

        if (! $shipment->external_label_id) {
            throw new \Exception('This shipment has no label to void.', 422);
        }

        if ($shipment->voided) {
            return $shipment->load('order:id,buyer_id,seller_id,status,tracking_number');
        }

        $isMockLabel = $this->shouldUseMock()
            || str_starts_with((string) $shipment->external_label_id, 'se-mock-')
            || str_starts_with((string) $shipment->external_label_id, 'mock-')
            || str_starts_with((string) $shipment->external_shipment_id, 'se-mock-');

        if (! $isMockLabel) {
            try {
                $this->client->voidLabel($shipment->external_label_id);
            } catch (Throwable $e) {
                if (! app()->isProduction() || $e instanceof ShipEngineAuthenticationException || $e instanceof ShipEngineNotFoundException) {
                    Log::warning('ShipEngine voidLabel remote call bypassed or failed, continuing with local void: '.$e->getMessage(), [
                        'shipment_id' => $shipment->id,
                        'external_label_id' => $shipment->external_label_id,
                    ]);
                } else {
                    $shipment->update(['error_message' => $this->sanitizeError($e)]);
                    throw $e;
                }
            }
        }

        $shipment->update([
            'voided' => true,
            'voided_at' => now(),
            'status' => ShipmentStatus::Voided->value,
            'error_message' => null,
        ]);

        // Auto-unsubscribe from tracking webhooks
        try {
            if (! $isMockLabel && $shipment->carrier_code && $shipment->tracking_number && ! str_starts_with((string) $shipment->tracking_number, 'MCK')) {
                $this->client->stopTracking($shipment->carrier_code, (string) $shipment->tracking_number);
            }
        } catch (Throwable $e) {
            Log::warning('Failed to unsubscribe from ShipEngine tracking webhook', [
                'shipment_id' => $shipment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $shipment->fresh()->load('order:id,buyer_id,seller_id,status,tracking_number');
    }

    // ─── Return Label ────────────────────────────────────────────────

    /**
     * Generate a return shipping label for a shipped order.
     * Swaps ship_from/ship_to so the buyer can send the package back.
     *
     * @return array<string, mixed>
     */
    public function createReturnLabel(
        Shipment $shipment,
        int $sellerId,
        string $labelFormat = 'pdf',
        string $labelLayout = '4x6',
    ): array {
        $this->assertSeller($shipment->order, $sellerId);

        if (! $shipment->hasActiveLabel()) {
            throw new \Exception('No active outbound label found. Cannot create return label.', 422);
        }

        $shipTo = $shipment->ship_from_snapshot ?? [];
        $shipFrom = $shipment->ship_to_snapshot ?? [];

        if (empty($shipTo) || empty($shipFrom)) {
            throw new \Exception('Shipment address snapshots are missing. Cannot create return label.', 422);
        }

        $payload = [
            'label_format' => $labelFormat,
            'label_layout' => $labelLayout,
            'is_return_label' => true,
            'rma_number' => 'RMA-ORD-'.($shipment->order_id ?? $shipment->id),
            'shipment' => [
                'service_code' => $shipment->service_code,
                'carrier_id' => $shipment->carrier_id,
                'ship_to' => $shipTo,
                'ship_from' => $shipFrom,
                'packages' => $shipment->package_snapshot ?? [['weight' => ['value' => 1, 'unit' => 'pound']]],
            ],
        ];

        if ($this->shouldUseMock()) {
            return [
                'label_id' => 'se-mock-return-lbl-'.$shipment->id,
                'label_format' => $labelFormat,
                'label_download_url' => url('/api/shipping/download-label') . '?label_id=' . $shipment->id . '&download=1',
                'tracking_number' => 'MCK-RETURN-'.strtoupper(substr(md5((string)$shipment->id), 0, 8)),
                'rma_number' => 'RMA-ORD-'.($shipment->order_id ?? $shipment->id),
                'carrier_code' => $shipment->carrier_code,
                'shipment_id' => 'se-mock-shp-return-'.$shipment->id,
            ];
        }

        $response = $this->client->createLabel($payload, $labelFormat, $labelLayout);

        return [
            'label_id' => $response['label_id'] ?? null,
            'label_format' => $response['label_format'] ?? $labelFormat,
            'label_download_url' => $response['label_download']['href'] ?? ($response['label_download']['pdf'] ?? null),
            'tracking_number' => $response['tracking_number'] ?? null,
            'rma_number' => 'RMA-ORD-'.($shipment->order_id ?? $shipment->id),
            'carrier_code' => $response['carrier_code'] ?? null,
            'shipment_id' => $response['shipment_id'] ?? null,
        ];
    }

    // ─── Tracking ───────────────────────────────────────────────────

    /**
     * Live tracking for a shipment (buyer and seller).
     *
     * @return array<string, mixed>
     */
    public function tracking(Shipment $shipment, int $profileId, ?string $carrierCode = null): array
    {
        $this->assertAccess($shipment, $profileId);

        if (! $shipment->tracking_number) {
            throw new \Exception('This shipment has no tracking number yet.', 422);
        }

        $carrier = $carrierCode ?? $shipment->carrier_code;

        if (! $carrier) {
            throw new \Exception('This shipment has no carrier code to track against.', 422);
        }

        $cacheKey = 'shipengine:tracking:'.$shipment->id.':'.$carrier;

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($shipment, $carrier) {
            try {
                $response = $this->client->getTracking($carrier, (string) $shipment->tracking_number);
                $trackingStatus = $this->processTrackingResponse($shipment, $response);

                return $this->normalizeTracking($response, $trackingStatus, $shipment);
            } catch (Throwable $e) {
                if ($this->shouldUseMock() || ! app()->isProduction() || str_starts_with((string) $shipment->tracking_number, 'MCK')) {
                    Log::warning('ShipEngine live tracking failed in sandbox/dev, returning mock tracking: '.$e->getMessage());

                    return $this->mockTrackingResponse($shipment, $carrier);
                }

                throw $e;
            }
        });
    }

    /**
     * Start tracking a shipment and subscribe to webhook updates.
     */
    public function startTracking(Shipment $shipment, int $profileId): array
    {
        $this->assertAccess($shipment, $profileId);

        if (! $shipment->tracking_number) {
            throw new \Exception('This shipment has no tracking number yet.', 422);
        }

        if (! $shipment->carrier_code) {
            throw new \Exception('This shipment has no carrier code to track against.', 422);
        }

        return $this->client->startTracking($shipment->carrier_code, (string) $shipment->tracking_number);
    }

    /**
     * Stop tracking a shipment and unsubscribe from webhook updates.
     */
    public function stopTracking(Shipment $shipment, int $profileId): array
    {
        $this->assertAccess($shipment, $profileId);

        if (! $shipment->tracking_number) {
            throw new \Exception('This shipment has no tracking number yet.', 422);
        }

        if (! $shipment->carrier_code) {
            throw new \Exception('This shipment has no carrier code to track against.', 422);
        }

        return $this->client->stopTracking($shipment->carrier_code, (string) $shipment->tracking_number);
    }

    /**
     * Background tracking sync: refreshes tracking_status and order state.
     */
    public function syncTrackingStatus(Shipment $shipment): void
    {
        if (! $shipment->tracking_number || ! $shipment->carrier_code || $shipment->voided) {
            return;
        }

        $response = $this->client->getTracking($shipment->carrier_code, (string) $shipment->tracking_number);

        $this->processTrackingResponse($shipment, $response);
    }

    // ─── Shared tracking processing ─────────────────────────────────

    /**
     * Process a raw ShipEngine tracking response: persist event history,
     * update shipment carriage state, and propagate to order.
     *
     * @param  array<string, mixed>  $response
     */
    public function processTrackingResponse(Shipment $shipment, array $response): ?string
    {
        $trackingStatus = $this->mapTrackingStatusCode((string) ($response['status_code'] ?? ''));

        // 1. Persist each carrier event (idempotent via unique key / tuple matching)
        foreach ((array) ($response['events'] ?? []) as $event) {
            ShipmentTrackingEvent::firstOrCreateByExternal($shipment->id, [
                'external_event_id' => $event['event_id'] ?? null,
                'status' => $this->mapTrackingStatusCode((string) ($event['event_code'] ?? $response['status_code'] ?? '')),
                'status_code' => isset($event['event_code']) ? (string) $event['event_code'] : null,
                'description' => $event['description'] ?? null,
                'city_locality' => $event['city_locality'] ?? null,
                'state_province' => $event['state_province'] ?? null,
                'postal_code' => $event['postal_code'] ?? null,
                'country_code' => isset($event['country_code']) ? substr((string) $event['country_code'], 0, 2) : null,
                'event_time' => isset($event['occurred_at']) ? date('Y-m-d H:i:s', strtotime((string) $event['occurred_at'])) : null,
                'raw_payload' => $event,
            ]);
        }

        // 2. Update shipment carriage state
        $updates = [
            'tracking_status' => $trackingStatus,
            'last_tracking_synced_at' => now(),
        ];

        if (! empty($response['estimated_delivery_date'])) {
            $updates['estimated_delivery_date'] = substr((string) $response['estimated_delivery_date'], 0, 10);
        }

        $shipmentStatus = $shipment->status !== null
            ? (ShipmentStatus::tryFrom($shipment->status) ?? ShipmentStatus::Created)
            : ShipmentStatus::Created;

        if ($trackingStatus !== null && ! $shipment->voided) {
            $carrierStatus = match ($trackingStatus) {
                'accepted' => ShipmentStatus::Shipped,
                'in_transit' => ShipmentStatus::InTransit,
                'out_for_delivery', 'delivery_attempt' => ShipmentStatus::OutForDelivery,
                'delivered', 'delivered_to_service_point' => ShipmentStatus::Delivered,
                default => null,
            };

            if ($carrierStatus !== null && $carrierStatus->progress() > $shipmentStatus->progress()) {
                $updates['status'] = $carrierStatus->value;

                if ($carrierStatus === ShipmentStatus::Shipped && $shipment->shipped_at === null) {
                    $updates['shipped_at'] = now();
                }
                if ($carrierStatus === ShipmentStatus::Delivered) {
                    $updates['delivered_at'] = $shipment->delivered_at ?? now();
                }

                $shipmentStatus = $carrierStatus;
            }
        }

        $shipment->update($updates);

        // 3. Propagate to parent order
        if ($shipment->status !== $shipmentStatus->value) {
            $shipment->refresh();
        }
        $this->propagateToOrder($shipment);

        return $trackingStatus;
    }

    /**
     * Map shipment carriage state onto parent order.
     */
    public function propagateToOrder(Shipment $shipment): void
    {
        $order = $shipment->order;
        if ($order === null || $shipment->voided) {
            return;
        }

        $activeShipments = $order->shipments()->where('voided', false)->get();

        if ($activeShipments->contains(fn (Shipment $s) => $s->status === ShipmentStatus::Delivered->value)
            && $activeShipments->every(fn (Shipment $s) => $s->status === ShipmentStatus::Delivered->value)
        ) {
            if (in_array($order->status, [OrderStatus::Shipped->value, OrderStatus::ReadyToShip->value, OrderStatus::Processing->value, OrderStatus::Delivered->value], true)) {
                $order->forceFill([
                    'status' => OrderStatus::Completed->value,
                    'delivered_at' => $order->delivered_at ?? now(),
                ])->save();

                $order->transaction()?->update(['status' => 'completed']);

                // Release escrow funds to seller upon verified carrier delivery
                app(ReleaseEscrowFundsAction::class)->execute($order);

                app(OrderTimelineService::class)->record(
                    $order,
                    OrderStatus::Completed->value,
                    null,
                    OrderTimelineService::ACTOR_SYSTEM,
                    "Delivered via carrier tracking webhook (shipment #{$shipment->id}: {$shipment->tracking_number}). Escrow automatically released to seller."
                );
            }

            return;
        }

        if ($order->status === OrderStatus::ReadyToShip->value
            && $activeShipments->contains(fn (Shipment $s) => in_array($s->status, [
                ShipmentStatus::Shipped->value,
                ShipmentStatus::InTransit->value,
                ShipmentStatus::OutForDelivery->value,
                ShipmentStatus::Delivered->value,
            ], true))
        ) {
            $order->forceFill(['status' => OrderStatus::Shipped->value])->save();

            app(OrderTimelineService::class)->record(
                $order,
                OrderStatus::Shipped->value,
                null,
                OrderTimelineService::ACTOR_SYSTEM,
                "Carrier accepted shipment #{$shipment->id} ({$shipment->carrier_code} {$shipment->tracking_number})."
            );
        }
    }

    // ─── Payload building ───────────────────────────────────────────

    /**
     * Build the ShipEngine shipment object (ship_to, ship_from, packages) from order.
     *
     * @return array<string, mixed>
     */
    protected function buildShipmentPayload(Order $order, ?string $validateAddress = null): array
    {
        $shipTo = $this->shipTo($order);
        $shipFrom = $this->shipFrom($order);

        $payload = [
            'validate_address' => $validateAddress ?? 'no_validation',
            'ship_to' => $shipTo->toPayload(),
            'ship_from' => $shipFrom->toPayload(),
            'packages' => array_map(
                fn (ShipEnginePackage $package) => $package->toPayload(),
                $this->packages($order)
            ),
        ];

        if ($shipFrom->countryCode !== $shipTo->countryCode) {
            $customsItems = [];
            foreach ($order->items as $item) {
                if ($item->product) {
                    $customsItems[] = [
                        'description' => substr((string) ($item->product->title ?? 'Merchandise'), 0, 50),
                        'quantity' => max(1, (int) $item->quantity),
                        'value' => [
                            'currency' => strtoupper((string) ($item->product->currency ?? $order->currency ?? 'USD')),
                            'amount' => round((float) ($item->unit_price ?? $item->product->price ?? 10.00), 2),
                        ],
                        'harmonized_tariff_code' => (string) ($item->product->ean_upc ?? ''),
                        'country_of_origin' => $shipFrom->countryCode,
                    ];
                }
            }

            if (! empty($customsItems)) {
                $payload['customs'] = [
                    'contents' => 'merchandise',
                    'non_delivery' => 'return_to_sender',
                    'customs_items' => $customsItems,
                ];
            }
        }

        return $payload;
    }

    protected function shipTo(Order $order): ShipEngineAddress
    {
        $address = $order->shipping_address;

        if (! is_array($address) || $address === []) {
            throw new \Exception('Order has no shipping address. Add a shipping address before shipping.', 422);
        }

        $fallbackName = $order->buyer?->full_name ?: ($order->buyer?->user?->name ?: 'Customer');

        return ShipEngineAddress::fromOrder($address, $fallbackName);
    }

    protected function shipFrom(?Order $order = null): ShipEngineAddress
    {
        // 1. Seller's User address — primary dynamic source, no env reliance
        if ($order !== null) {
            $order->loadMissing('seller.user');
            $sellerUser = $order->seller?->user;

            if (
                $sellerUser &&
                ! empty($sellerUser->address_line_1) &&
                ! empty($sellerUser->city) &&
                ! empty($sellerUser->postal_code) &&
                ! empty($sellerUser->country)
            ) {
                $countryCode = ShipEngineAddress::normalizeCountryCode((string) $sellerUser->country);

                return new ShipEngineAddress(
                    name: $sellerUser->name ?: ($order->seller->full_name ?: 'Seller'),
                    addressLine1: (string) $sellerUser->address_line_1,
                    cityLocality: (string) $sellerUser->city,
                    postalCode: (string) $sellerUser->postal_code,
                    countryCode: $countryCode,
                    companyName: $order->seller->full_name ?? null,
                    addressLine2: $sellerUser->address_line_2 ? (string) $sellerUser->address_line_2 : null,
                    stateProvince: $countryCode === 'US'
                        ? (ShipEngineAddress::normalizeUsState($sellerUser->state ?? null) ?? 'TX')
                        : null,
                    phone: $sellerUser->mobile_number ? (string) $sellerUser->mobile_number : '+1 555-555-5555',
                    email: $sellerUser->email ? (string) $sellerUser->email : null,
                );
            }

            // 2. Product-level ship_from_address override
            $order->loadMissing('items.product');
            foreach ($order->items as $item) {
                $product = $item->product;
                if ($product && ! empty($product->ship_from_address)) {
                    $parsed = $this->parseAddressData($product->ship_from_address, 'Seller Warehouse');
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
            }
        }

        // 3. .env / config fallback (optional)
        $config = config('shipengine.ship_from') ?: (config('services.shipengine.ship_from') ?? []);
        if (is_array($config) && ! empty($config['line1'] ?? $config['address_line1'] ?? null)) {
            try {
                return ShipEngineAddress::fromConfig($config);
            } catch (Throwable) {
                // fall through
            }
        }

        // 4. No usable address — fail explicitly
        throw new \Exception(
            'Seller shipping address is incomplete. Please update your profile with address line 1, city, postal code, and country before creating shipments.',
            422
        );
    }

    public function parseAddressData(mixed $data, string $fallbackName = 'Warehouse', ?string $sellerCountryFallback = null): ?ShipEngineAddress
    {
        if (is_string($data)) {
            $trimmed = trim($data);
            if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            } else {
                $parts = array_values(array_filter(array_map('trim', explode(',', $trimmed))));
                $count = count($parts);
                if ($count >= 1) {
                    // Try to identify country from the last part
                    $countryCandidate = end($parts);
                    $countryCode = null;
                    try {
                        $countryCode = ShipEngineAddress::normalizeCountryCode($countryCandidate);
                        // If successfully normalized, the last part is indeed a country
                        array_pop($parts);
                        $count = count($parts);
                    } catch (Throwable) {
                        // Candidate was not a standalone country name, check seller country fallback
                        try {
                            $countryCode = $sellerCountryFallback ? ShipEngineAddress::normalizeCountryCode($sellerCountryFallback) : 'US';
                        } catch (Throwable) {
                            $countryCode = 'US';
                        }
                    }

                    $postal = '00000';
                    $state = null;
                    $city = 'City';
                    $line1 = $trimmed;

                    if ($count >= 2) {
                        $line1 = $parts[0];
                        $lastRemaining = $parts[$count - 1];

                        // Check if lastRemaining has "State Postal" or "City Postal"
                        if (preg_match('/^(.*?)\s*(\b\d{4,6}\b)$/', $lastRemaining, $m)) {
                            $city = trim($m[1]) !== '' ? trim($m[1]) : ($parts[1] ?? 'City');
                            $postal = $m[2];
                        } else {
                            $city = $lastRemaining;
                        }

                        if ($count >= 3) {
                            $line1 = implode(', ', array_slice($parts, 0, $count - 2));
                            $city = $parts[$count - 2];
                        }
                    } elseif ($count === 1) {
                        $line1 = $parts[0];
                        $city = $parts[0];
                    }

                    try {
                        return new ShipEngineAddress(
                            name: $fallbackName,
                            addressLine1: $line1,
                            cityLocality: $city,
                            postalCode: $postal,
                            countryCode: $countryCode,
                            stateProvince: $state,
                        );
                    } catch (Throwable) {
                        return null;
                    }
                }
            }
        }

        if (is_array($data)) {
            $line1 = trim((string) ($data['line1'] ?? $data['address_line1'] ?? ''));
            $city = trim((string) ($data['city'] ?? $data['city_locality'] ?? ''));
            $postal = trim((string) ($data['postal_code'] ?? '00000'));
            $country = trim((string) ($data['country'] ?? $data['country_code'] ?? ($sellerCountryFallback ?? 'US')));
            $name = trim((string) ($data['name'] ?? $fallbackName));

            if ($line1 !== '' && $city !== '') {
                try {
                    return new ShipEngineAddress(
                        name: $name !== '' ? $name : $fallbackName,
                        addressLine1: $line1,
                        cityLocality: $city,
                        postalCode: $postal !== '' ? $postal : '00000',
                        countryCode: ShipEngineAddress::normalizeCountryCode($country),
                        companyName: isset($data['company_name']) ? (string) $data['company_name'] : null,
                        addressLine2: isset($data['line2']) ? (string) $data['line2'] : null,
                        stateProvince: isset($data['state']) ? (string) $data['state'] : null,
                        phone: ! empty($data['phone']) ? (string) $data['phone'] : '+1 555-555-5555',
                        email: isset($data['email']) ? (string) $data['email'] : null,
                    );
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * One package per shippable line item.
     *
     * @return array<int, ShipEnginePackage>
     */
    protected function packages(Order $order): array
    {
        $order->loadMissing('items.product');
        $config = config('shipengine') ?: (config('services.shipengine') ?? []);

        $packages = [];

        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            // Exclude dropshipped AliExpress items from ShipEngine package calculation
            if (strtolower((string) $product->source) === 'aliexpress' || ! empty($product->aliexpress_product_id)) {
                continue;
            }

            $packages[] = ShipEnginePackage::fromProduct($product, (int) $item->quantity, $config);
        }

        if ($packages === []) {
            throw new \Exception('Order has no shippable items with package information for carrier shipping.', 422);
        }

        return $packages;
    }

    // ─── Response normalization ─────────────────────────────────────

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRates(array $response): array
    {
        $rateResponse = $response['rate_response'] ?? $response;
        $rates = is_array($rateResponse['rates'] ?? null) ? $rateResponse['rates'] : [];
        $invalidRates = is_array($rateResponse['invalid_rates'] ?? null) ? $rateResponse['invalid_rates'] : [];

        if (empty($rates) && ! empty($invalidRates)) {
            $errors = array_map(fn (array $r) => [
                'carrier_code' => $r['carrier_code'] ?? null,
                'service_type' => $r['service_type'] ?? null,
                'error_messages' => $r['error_messages'] ?? [],
            ], $invalidRates);

            Log::warning('ShipEngine: all rates invalid', ['invalid_rates' => $errors]);

            throw new \Exception(
                'No valid shipping rates available. Carrier errors: '
                .implode('; ', array_map(
                    fn ($r) => ($r['service_type'] ?? 'Unknown').': '.implode(', ', $r['error_messages']),
                    $errors
                )),
                422
            );
        }

        return array_map(fn (array $rate) => [
            'rate_id' => $rate['rate_id'] ?? null,
            'carrier_id' => $rate['carrier_id'] ?? null,
            'carrier_code' => $rate['carrier_code'] ?? null,
            'carrier_friendly_name' => $rate['carrier_friendly_name'] ?? null,
            'service_code' => $rate['service_code'] ?? null,
            'service_type' => $rate['service_type'] ?? null,
            'shipping_amount' => isset($rate['shipping_amount']['amount']) ? (float) $rate['shipping_amount']['amount'] : null,
            'currency' => strtoupper((string) ($rate['shipping_amount']['currency'] ?? 'usd')),
            'delivery_days' => $rate['delivery_days'] ?? null,
            'estimated_delivery_date' => $rate['estimated_delivery_date'] ?? null,
            'trackable' => (bool) ($rate['trackable'] ?? false),
            'rate_attributes' => $rate['rate_attributes'] ?? [],
            'warning_messages' => $rate['warning_messages'] ?? [],
        ], $rates);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function normalizeTracking(array $response, ?string $trackingStatus, ?Shipment $shipment = null): array
    {
        $milestones = $shipment !== null ? $this->buildMilestones($shipment, $trackingStatus, $response) : [];

        return [
            'tracking_number' => $response['tracking_number'] ?? $shipment?->tracking_number,
            'tracking_url' => $response['tracking_url'] ?? null,
            'status_code' => $response['status_code'] ?? null,
            'status_description' => $response['status_description'] ?? null,
            'tracking_status' => $trackingStatus ?? $shipment?->tracking_status,
            'carrier_code' => $response['carrier_code'] ?? $shipment?->carrier_code,
            'estimated_delivery_date' => $response['estimated_delivery_date'] ?? $shipment?->estimated_delivery_date,
            'actual_delivery_date' => $response['actual_delivery_date'] ?? $shipment?->delivered_at?->toIso8601String(),
            'milestones' => $milestones,
            'events' => array_map(fn (array $event) => [
                'occurred_at' => $event['occurred_at'] ?? null,
                'description' => $event['description'] ?? null,
                'city_locality' => $event['city_locality'] ?? null,
                'state_province' => $event['state_province'] ?? null,
                'postal_code' => $event['postal_code'] ?? null,
                'country_code' => $event['country_code'] ?? null,
                'event_code' => $event['event_code'] ?? null,
            ], $response['events'] ?? []),
        ];
    }

    public function buildMilestones(Shipment $shipment, ?string $trackingStatus, array $response = []): array
    {
        $status = strtolower((string) ($trackingStatus ?? $shipment->tracking_status ?? $shipment->status ?? ''));

        $isDelivered = in_array($status, ['delivered', 'delivered_to_service_point'], true) || $shipment->status === ShipmentStatus::Delivered->value;
        $isOutForDelivery = $isDelivered || in_array($status, ['out_for_delivery', 'delivery_attempt'], true) || $shipment->status === ShipmentStatus::OutForDelivery->value;
        $isInTransit = $isOutForDelivery || in_array($status, ['in_transit', 'accepted', 'shipped'], true) || in_array($shipment->status, [ShipmentStatus::Shipped->value, ShipmentStatus::InTransit->value], true);
        $isOrdered = true;

        $events = (array) ($response['events'] ?? []);
        $deliveredTime = $shipment->delivered_at?->toIso8601String();
        $outForDeliveryTime = null;
        $inTransitTime = $shipment->shipped_at?->toIso8601String();
        $orderedTime = $shipment->order?->created_at?->toIso8601String() ?? $shipment->created_at?->toIso8601String();

        foreach ($events as $event) {
            $eCode = strtoupper((string) ($event['event_code'] ?? ''));
            $eTime = $event['occurred_at'] ?? null;
            if ($eTime) {
                if (in_array($eCode, ['DE', 'SP'], true)) {
                    $deliveredTime = $deliveredTime ?? $eTime;
                } elseif (in_array($eCode, ['AT', 'OD'], true) || str_contains(strtolower($event['description'] ?? ''), 'out for delivery')) {
                    $outForDeliveryTime = $outForDeliveryTime ?? $eTime;
                } elseif (in_array($eCode, ['IT', 'NY', 'AC'], true)) {
                    $inTransitTime = $inTransitTime ?? $eTime;
                }
            }
        }

        return [
            [
                'step' => 1,
                'key' => 'ordered',
                'title' => 'Ordered',
                'description' => 'Order placed and shipping label created.',
                'completed' => $isOrdered,
                'current' => ! $isInTransit || ($isOrdered && ! $isInTransit),
                'timestamp' => $orderedTime,
            ],
            [
                'step' => 2,
                'key' => 'in_transit',
                'title' => 'In Transit',
                'description' => 'Package has been received by the carrier and is in transit.',
                'completed' => $isInTransit,
                'current' => $isInTransit && ! $isOutForDelivery,
                'timestamp' => $inTransitTime,
            ],
            [
                'step' => 3,
                'key' => 'out_for_delivery',
                'title' => 'Out for Delivery',
                'description' => 'Package is out for local delivery to the destination.',
                'completed' => $isOutForDelivery,
                'current' => $isOutForDelivery && ! $isDelivered,
                'timestamp' => $outForDeliveryTime,
            ],
            [
                'step' => 4,
                'key' => 'delivered',
                'title' => 'Delivered',
                'description' => 'Package has been successfully delivered.',
                'completed' => $isDelivered,
                'current' => $isDelivered,
                'timestamp' => $deliveredTime,
            ],
        ];
    }

    /**
     * Deterministically resolve carrier and service code for an order based on
     * the chosen shipping tier (standard_shipping, express_shipping, free_shipping,
     * international_shipping) and connected carriers.
     *
     * @return array{0: string, 1: string} [carrierId, serviceCode]
     */
    public function resolveCarrierAndServiceForOrder(Order $order, ?string $carrierId = null, ?string $serviceCode = null, ?string $carrierCode = null): array
    {
        if ($carrierId !== null && $serviceCode !== null) {
            return [$carrierId, $serviceCode];
        }

        $connectedCarriers = [];
        try {
            $connectedCarriers = $this->carriers();
        } catch (Throwable) {
            // fallback if carrier listing fails
        }

        $method = ShippingOptionDTO::normalizeKey((string) ($order->shipping_method ?? 'standard_shipping'))
            ?? ShippingOptionDTO::STANDARD_SHIPPING;

        $preferredServices = match ($method) {
            'express_shipping' => [
                'fedex_2day', 'usps_priority_mail_express', 'fedex_priority_overnight', 'fedex_standard_overnight',
                'ups_second_day_air', 'ups_next_day_air', 'usps_priority_mail',
            ],
            'free_shipping' => [
                'usps_ground_advantage', 'fedex_ground', 'ups_ground', 'usps_first_class_mail',
            ],
            'international_shipping' => [
                'usps_first_class_mail_international', 'usps_priority_mail_international',
                'fedex_international_economy', 'fedex_international_priority',
                'ups_worldwide_expedited', 'ups_worldwide_saver',
            ],
            default => [
                'usps_priority_mail', 'fedex_ground', 'usps_ground_advantage',
                'ups_ground', 'usps_first_class_mail',
            ],
        };

        if ($carrierId === null && ! empty($carrierCode)) {
            foreach ($connectedCarriers as $carrier) {
                if (($carrier['carrier_code'] ?? null) === $carrierCode) {
                    $resolvedCarrierId = (string) $carrier['carrier_id'];

                    if ($serviceCode !== null) {
                        return [$resolvedCarrierId, $serviceCode];
                    }

                    foreach ($preferredServices as $preferred) {
                        foreach ($carrier['services'] ?? [] as $service) {
                            if (($service['code'] ?? null) === $preferred) {
                                return [$resolvedCarrierId, $preferred];
                            }
                        }
                    }

                    $firstService = $carrier['services'][0]['code'] ?? null;
                    if ($firstService) {
                        return [$resolvedCarrierId, $firstService];
                    }
                }
            }
        }

        if ($carrierId !== null && $serviceCode === null) {
            foreach ($connectedCarriers as $carrier) {
                if (($carrier['carrier_id'] ?? null) === $carrierId) {
                    foreach ($preferredServices as $preferred) {
                        foreach ($carrier['services'] ?? [] as $service) {
                            if (($service['code'] ?? null) === $preferred) {
                                return [$carrierId, $preferred];
                            }
                        }
                    }
                    $firstService = $carrier['services'][0]['code'] ?? null;
                    if ($firstService) {
                        return [$carrierId, $firstService];
                    }
                }
            }
        }

        if ($carrierId === null && $serviceCode !== null) {
            foreach ($connectedCarriers as $carrier) {
                foreach ($carrier['services'] ?? [] as $service) {
                    if (($service['code'] ?? null) === $serviceCode) {
                        return [(string) $carrier['carrier_id'], $serviceCode];
                    }
                }
            }
        }

        foreach ($preferredServices as $preferred) {
            foreach ($connectedCarriers as $carrier) {
                foreach ($carrier['services'] ?? [] as $service) {
                    if (($service['code'] ?? null) === $preferred) {
                        return [(string) $carrier['carrier_id'], $preferred];
                    }
                }
            }
        }

        if (! empty($connectedCarriers)) {
            $firstCarrier = $connectedCarriers[0];
            $cId = (string) ($firstCarrier['carrier_id'] ?? '');
            $sCode = (string) ($firstCarrier['services'][0]['code'] ?? '');
            if ($cId !== '' && $sCode !== '') {
                return [$carrierId ?? $cId, $serviceCode ?? $sCode];
            }
        }

        $defaultCarrierId = (string) config('shipengine.default_carrier_id', 'se-carrier-default');
        $defaultServiceCode = match ($method) {
            'express_shipping' => 'fedex_2day',
            'free_shipping' => 'usps_ground_advantage',
            'international_shipping' => 'usps_first_class_mail_international',
            default => 'usps_priority_mail',
        };

        return [$carrierId ?? $defaultCarrierId, $serviceCode ?? $defaultServiceCode];
    }

    protected function mapTrackingStatusCode(string $code): ?string
    {
        return match (strtoupper($code)) {
            'AC' => 'accepted',
            'IT', 'NY' => 'in_transit',
            'DE' => 'delivered',
            'EX' => 'error',
            'AT' => 'delivery_attempt',
            'SP' => 'delivered_to_service_point',
            'UN' => 'unknown',
            default => null,
        };
    }

    // ─── Persistence ────────────────────────────────────────────────

    /**
     * Persist only the label metadata the application needs into the shipments table.
     *
     * @param  array<string, mixed>  $response
     */
    protected function persistLabel(Shipment $shipment, array $response): void
    {
        $cost = is_array($response['shipment_cost'] ?? null) ? $response['shipment_cost'] : [];
        $download = is_array($response['label_download'] ?? null) ? $response['label_download'] : [];
        $trackingNumber = $response['tracking_number'] ?? null;
        $trackingStatus = $response['tracking_status'] ?? 'in_transit';

        $shipment->update([
            'external_label_id' => $response['label_id'] ?? null,
            'external_shipment_id' => $response['shipment_id'] ?? $shipment->external_shipment_id,
            'carrier_id' => $response['carrier_id'] ?? $shipment->carrier_id,
            'carrier_code' => $response['carrier_code'] ?? null,
            'service_code' => $response['service_code'] ?? $shipment->service_code,
            'label_status' => $response['status'] ?? null,
            'tracking_number' => $trackingNumber,
            'tracking_status' => $trackingStatus,
            'shipping_cost' => isset($cost['amount']) ? (float) $cost['amount'] : null,
            'currency' => strtoupper((string) ($cost['currency'] ?? 'usd')),
            'ship_date' => isset($response['ship_date'])
                ? substr((string) $response['ship_date'], 0, 10)
                : null,
            'label_format' => $response['label_format'] ?? 'pdf',
            'label_download_url' => $download['href'] ?? ($download['pdf'] ?? null),
            'status' => ShipmentStatus::Shipped->value,
            'shipped_at' => now(),
            'label_created_at' => now(),
            'error_message' => null,
        ]);

        $order = $shipment->order;
        if ($order) {
            $order->update([
                'status' => OrderStatus::Shipped->value,
                'shipped_at' => $order->shipped_at ?? now(),
                'tracking_number' => $trackingNumber ?? $order->tracking_number,
            ]);

            app(OrderTimelineService::class)->record(
                $order,
                OrderStatus::Shipped->value,
                null,
                OrderTimelineService::ACTOR_SYSTEM,
                "Order shipped with label purchased via ShipEngine (Tracking: {$trackingNumber})."
            );
        }

        // Auto-subscribe to tracking webhooks
        try {
            if ($shipment->carrier_code && $shipment->tracking_number) {
                $this->client->startTracking($shipment->carrier_code, (string) $shipment->tracking_number);
            }
        } catch (Throwable $e) {
            Log::warning('Failed to subscribe to ShipEngine tracking webhook', [
                'shipment_id' => $shipment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─── Authorization & helpers ────────────────────────────────────

    public function isSellerOrAdmin(Order $order, int $userId): bool
    {
        $user = Auth::user() ?? Auth::guard('jwt')->user();
        $authUserId = $user ? (int) $user->id : $userId;
        $authProfileId = $user?->profile ? (int) $user->profile->id : $userId;
        $candidateIds = array_unique(array_filter([$userId, $authUserId, $authProfileId]));

        // Admin check
        if ($user && ($user->role === 'admin' || (method_exists($user, 'hasRole') && $user->hasRole('admin')))) {
            return true;
        }

        // Order direct seller check
        if (in_array((int) $order->seller_id, $candidateIds, true)) {
            return true;
        }

        // Allow if seller owns any items directly in order_items
        if (Schema::hasColumn('order_items', 'seller_id')) {
            if ($order->items()->whereIn('seller_id', $candidateIds)->exists()) {
                return true;
            }
        }

        // Allow if seller owns products in order_items
        $order->loadMissing('items.product.seller');
        $ownsProduct = $order->items->contains(function ($item) use ($candidateIds) {
            if (! $item->product) {
                return false;
            }
            $pSellerId = (int) $item->product->seller_id;
            $pSellerUserId = (int) ($item->product->seller?->user_id ?? 0);

            return in_array($pSellerId, $candidateIds, true)
                || in_array($pSellerUserId, $candidateIds, true);
        });

        return $ownsProduct;
    }

    protected function assertSeller(Order $order, int $sellerId): void
    {
        if ($this->isSellerOrAdmin($order, $sellerId)) {
            return;
        }

        throw new \Exception('Only the seller of this order can perform this action.', 403);
    }

    protected function assertBuyer(Order $order, int $buyerId): void
    {
        $user = Auth::user() ?? Auth::guard('jwt')->user();
        $authUserId = $user ? (int) $user->id : $buyerId;
        $authProfileId = $user?->profile ? (int) $user->profile->id : $buyerId;
        $candidateIds = array_unique(array_filter([$buyerId, $authUserId, $authProfileId]));

        if (
            in_array((int) $order->buyer_id, $candidateIds, true)
            || in_array((int) ($order->user_id ?? 0), $candidateIds, true)
        ) {
            return;
        }

        // Backward compatibility: allow seller or admin
        if ($this->isSellerOrAdmin($order, $buyerId)) {
            return;
        }

        throw new \Exception('Only the buyer of this order can perform this action.', 403);
    }

    protected function assertBuyerOrSeller(Order $order, int $userId): void
    {
        $user = Auth::user() ?? Auth::guard('jwt')->user();
        $authUserId = $user ? (int) $user->id : $userId;
        $authProfileId = $user?->profile ? (int) $user->profile->id : $userId;
        $candidateIds = array_unique(array_filter([$userId, $authUserId, $authProfileId]));

        // 1. Buyer check
        if (
            in_array((int) $order->buyer_id, $candidateIds, true)
            || in_array((int) ($order->user_id ?? 0), $candidateIds, true)
        ) {
            return;
        }

        // 2. Seller or Admin check
        if ($this->isSellerOrAdmin($order, $userId)) {
            return;
        }

        throw new \Exception('Only the buyer or seller of this order can perform this action.', 403);
    }

    public function assertAccess(Shipment $shipment, int $profileOrUserId): void
    {
        $user = Auth::user() ?? Auth::guard('jwt')->user();
        if ($user && ($user->role === 'admin' || (method_exists($user, 'hasRole') && $user->hasRole('admin')))) {
            return;
        }

        $authUserId = $user ? (int) $user->id : $profileOrUserId;
        $authProfileId = $user?->profile ? (int) $user->profile->id : $profileOrUserId;
        $candidateIds = array_values(array_unique(array_filter([$profileOrUserId, $authUserId, $authProfileId])));

        // Direct shipment seller check
        if (in_array((int) $shipment->seller_id, $candidateIds, true)) {
            return;
        }

        $order = $shipment->order;
        if (! $order) {
            throw new \Exception('Shipment not found.', 404);
        }

        // Buyer check
        if (
            in_array((int) $order->buyer_id, $candidateIds, true)
            || in_array((int) ($order->user_id ?? 0), $candidateIds, true)
        ) {
            return;
        }

        // Seller or admin check (isSellerOrAdmin checks order seller_id, order_items, product owner)
        if ($this->isSellerOrAdmin($order, $profileOrUserId)) {
            return;
        }

        throw new \Exception('Shipment not found.', 404);
    }

    protected function assertReadyToShip(Order $order): void
    {
        $order->loadMissing('items.product');

        if ($order->isAliExpressFulfilled()) {
            $hasLocalShippable = $order->items->contains(function ($item) {
                return $item->product && strtolower((string) $item->product->source) !== 'aliexpress' && empty($item->product->aliexpress_product_id);
            });

            if (! $hasLocalShippable) {
                throw new \Exception('AliExpress dropshipping orders cannot use ShipEngine. Please use AliExpress fulfillment.', 422);
            }
        }

        if (! in_array($order->status, [
            OrderStatus::ReadyToShip->value,
            OrderStatus::Confirmed->value,
            OrderStatus::Processing->value,
            OrderStatus::Shipped->value,
            OrderStatus::Pending->value,
            'ready_to_ship',
            'confirmed',
            'processing',
            'shipped',
            'paid',
            'pending',
        ], true)) {
            throw new \Exception(
                "Order must be confirmed or ready to ship before shipping. Current status: {$order->status}.",
                422
            );
        }
    }

    protected function sanitizeError(Throwable $e): string
    {
        return mb_substr((string) $e->getMessage(), 0, 500);
    }

    /**
     * Check if the ShipEngine integration is configured with a valid API key.
     */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Check whether to use sandbox/mock responses for development and staging.
     */
    public function shouldUseMock(): bool
    {
        if (config('services.shipengine.mock_mode', config('shipengine.mock_mode', false))) {
            return true;
        }

        if (app()->environment('testing')) {
            return true;
        }

        if (! app()->isProduction() && ! $this->client->isConfigured()) {
            return true;
        }

        return false;
    }

    /**
     * Realistic mock carrier list for local/staging environments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mockCarriers(): array
    {
        return [
            [
                'carrier_id' => 'se-360751',
                'carrier_code' => 'royal_mail',
                'friendly_name' => 'Royal Mail',
                'services' => [
                    [
                        'carrier_id' => 'se-360751',
                        'code' => 'royal_mail_track_48',
                        'name' => 'Royal Mail Tracked 48',
                    ],
                    [
                        'carrier_id' => 'se-360751',
                        'code' => 'royal_mail_track_24',
                        'name' => 'Royal Mail Tracked 24',
                    ],
                ],
            ],
            [
                'carrier_id' => 'se-360749',
                'carrier_code' => 'dpdwallet',
                'friendly_name' => 'DPD Express',
                'services' => [
                    [
                        'carrier_id' => 'se-360749',
                        'code' => 'dpd_next_day',
                        'name' => 'DPD Next Day',
                    ],
                ],
            ],
            [
                'carrier_id' => 'se-360748',
                'carrier_code' => 'hermes',
                'friendly_name' => 'Evri (Hermes)',
                'services' => [
                    [
                        'carrier_id' => 'se-360748',
                        'code' => 'evri_standard',
                        'name' => 'Evri Standard Delivery',
                    ],
                ],
            ],
            [
                'carrier_id' => 'se-360752',
                'carrier_code' => 'fedex',
                'friendly_name' => 'FedEx Express',
                'services' => [
                    [
                        'carrier_id' => 'se-360752',
                        'code' => 'fedex_ground',
                        'name' => 'FedEx Ground',
                    ],
                ],
            ],
        ];
    }

    /**
     * Fallback carrier list derived from config/services.php or env carrier IDs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fallbackCarriers(): array
    {
        $configuredIds = config('services.shipengine.carrier_ids', config('shipengine.carrier_ids', []));
        if (is_string($configuredIds)) {
            $configuredIds = array_filter(array_map('trim', explode(',', $configuredIds)));
        }

        if (empty($configuredIds)) {
            return $this->mockCarriers();
        }

        $knownCarriers = [
            'se-360748' => ['carrier_code' => 'hermes', 'friendly_name' => 'Evri (Hermes)'],
            'se-360749' => ['carrier_code' => 'dpdwallet', 'friendly_name' => 'DPD'],
            'se-360750' => ['carrier_code' => 'globalpost', 'friendly_name' => 'GlobalPost'],
            'se-360751' => ['carrier_code' => 'royal_mail', 'friendly_name' => 'Royal Mail'],
            'se-360752' => ['carrier_code' => 'fedex', 'friendly_name' => 'FedEx'],
            'se-399629' => ['carrier_code' => 'pf_cnd_walleted', 'friendly_name' => 'Parcelforce Royal Mail'],
            'se-399630' => ['carrier_code' => 'parcelforce_worldwide', 'friendly_name' => 'Parcelforce Worldwide'],
        ];

        $carriers = [];
        foreach ($configuredIds as $id) {
            $meta = $knownCarriers[$id] ?? [
                'carrier_code' => 'carrier_'.substr($id, 3),
                'friendly_name' => 'Carrier ('.$id.')',
            ];

            $carriers[] = [
                'carrier_id' => $id,
                'carrier_code' => $meta['carrier_code'],
                'friendly_name' => $meta['friendly_name'],
                'services' => [
                    [
                        'carrier_id' => $id,
                        'code' => $meta['carrier_code'].'_standard',
                        'name' => $meta['friendly_name'].' Standard',
                    ],
                    [
                        'carrier_id' => $id,
                        'code' => $meta['carrier_code'].'_express',
                        'name' => $meta['friendly_name'].' Express',
                    ],
                ],
            ];
        }

        return $carriers;
    }

    /**
     * Realistic mock shipping rates for local/staging environments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mockRates(Order $order): array
    {
        $currency = strtoupper((string) ($order->shipping_currency ?: $order->currency ?: 'USD'));
        $now = now();

        return [
            [
                'rate_id' => 'se-mock-rate-'.$order->id.'-standard',
                'carrier_id' => 'se-360751',
                'carrier_code' => 'royal_mail',
                'carrier_friendly_name' => 'Royal Mail',
                'service_code' => 'royal_mail_track_48',
                'service_type' => 'Royal Mail Tracked 48',
                'shipping_amount' => 3.99,
                'currency' => $currency,
                'delivery_days' => 2,
                'estimated_delivery_date' => $now->copy()->addDays(2)->toIso8601String(),
                'trackable' => true,
                'rate_attributes' => [],
                'warning_messages' => [],
            ],
            [
                'rate_id' => 'se-mock-rate-'.$order->id.'-express',
                'carrier_id' => 'se-360749',
                'carrier_code' => 'dpdwallet',
                'carrier_friendly_name' => 'DPD Express',
                'service_code' => 'dpd_next_day',
                'service_type' => 'DPD Next Day',
                'shipping_amount' => 6.99,
                'currency' => $currency,
                'delivery_days' => 1,
                'estimated_delivery_date' => $now->copy()->addDay()->toIso8601String(),
                'trackable' => true,
                'rate_attributes' => [],
                'warning_messages' => [],
            ],
            [
                'rate_id' => 'se-mock-rate-'.$order->id.'-economy',
                'carrier_id' => 'se-360748',
                'carrier_code' => 'hermes',
                'carrier_friendly_name' => 'Evri (Hermes)',
                'service_code' => 'evri_standard',
                'service_type' => 'Evri Standard Delivery',
                'shipping_amount' => 2.99,
                'currency' => $currency,
                'delivery_days' => 3,
                'estimated_delivery_date' => $now->copy()->addDays(3)->toIso8601String(),
                'trackable' => true,
                'rate_attributes' => [],
                'warning_messages' => [],
            ],
        ];
    }

    /**
     * Mock label response for non-production label purchases.
     *
     * @return array<string, mixed>
     */
    public function mockLabelResponse(Shipment $shipment): array
    {
        $trackingNumber = 'MCK'.strtoupper(Str::random(12));

        return [
            'label_id' => 'se-mock-lbl-'.$shipment->id,
            'shipment_id' => 'se-mock-shp-'.$shipment->id,
            'carrier_id' => $shipment->carrier_id ?? 'se-360751',
            'carrier_code' => $shipment->carrier_code ?? 'royal_mail',
            'service_code' => $shipment->service_code ?? 'royal_mail_track_48',
            'status' => 'completed',
            'tracking_number' => $trackingNumber,
            'tracking_status' => 'in_transit',
            'shipment_cost' => [
                'currency' => 'usd',
                'amount' => 3.99,
            ],
            'ship_date' => now()->toIso8601String(),
            'label_format' => 'pdf',
            'label_download' => [
                'href' => url('/api/shipping/download-label').'?label_id='.$shipment->id.'&download=1',
                'pdf' => url('/api/shipping/download-label').'?label_id='.$shipment->id.'&download=1',
            ],
        ];
    }

    /**
     * Generate mock tracking data for non-production or test shipments.
     *
     * @return array<string, mixed>
     */
    public function mockTrackingResponse(Shipment $shipment, ?string $carrier = null): array
    {
        $carrierCode = $carrier ?? $shipment->carrier_code ?? 'royal_mail';
        $trackingNumber = (string) ($shipment->tracking_number ?? 'MCK1234567890');
        $shippedAt = $shipment->shipped_at ?? now()->subDay();
        $status = $shipment->status ?? ShipmentStatus::Shipped->value;

        $trackingStatus = match ($status) {
            ShipmentStatus::Delivered->value => 'delivered',
            ShipmentStatus::OutForDelivery->value => 'out_for_delivery',
            default => 'in_transit',
        };

        $events = [
            [
                'occurred_at' => $shippedAt->toIso8601String(),
                'carrier_occurred_at' => $shippedAt->toIso8601String(),
                'description' => 'Package accepted by carrier at origin sorting center.',
                'city_locality' => 'London',
                'state_province' => 'Greater London',
                'postal_code' => 'SW1A 1AA',
                'country_code' => 'GB',
                'event_code' => 'AC',
            ],
            [
                'occurred_at' => $shippedAt->copy()->addHours(6)->toIso8601String(),
                'carrier_occurred_at' => $shippedAt->copy()->addHours(6)->toIso8601String(),
                'description' => 'In transit to destination facility.',
                'city_locality' => 'Birmingham',
                'state_province' => 'West Midlands',
                'postal_code' => 'B1 1AA',
                'country_code' => 'GB',
                'event_code' => 'IT',
            ],
        ];

        if ($trackingStatus === 'delivered') {
            $events[] = [
                'occurred_at' => ($shipment->delivered_at ?? now())->toIso8601String(),
                'carrier_occurred_at' => ($shipment->delivered_at ?? now())->toIso8601String(),
                'description' => 'Delivered to recipient.',
                'city_locality' => 'Manchester',
                'state_province' => 'Greater Manchester',
                'postal_code' => 'M1 1AA',
                'country_code' => 'GB',
                'event_code' => 'DE',
            ];
        }

        $rawResponse = [
            'tracking_number' => $trackingNumber,
            'tracking_url' => "https://track.shipengine.com/{$carrierCode}/{$trackingNumber}",
            'status_code' => $trackingStatus === 'delivered' ? 'DE' : 'IT',
            'status_description' => $trackingStatus === 'delivered' ? 'Delivered' : 'In Transit',
            'carrier_code' => $carrierCode,
            'estimated_delivery_date' => $shipment->estimated_delivery_date ?? now()->addDays(2)->toIso8601String(),
            'actual_delivery_date' => $shipment->delivered_at?->toIso8601String(),
            'events' => $events,
        ];

        return $this->normalizeTracking($rawResponse, $trackingStatus, $shipment);
    }
}
