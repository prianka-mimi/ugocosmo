<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ShippingOptionDTO;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dynamic Shipping Rate Calculation Service.
 *
 * Computes live, carrier-calculated shipping rates based on:
 * - Package dimensions (length, width, height, unit)
 * - Item physical weight (weight, weight unit)
 * - Quantity aggregation
 * - Origin address (seller ship_from)
 * - Destination address (buyer ship_to)
 * - Real-time quotes from ShipStation / ShipEngine multi-carrier API
 */
final class ShippingRateCalculatorService
{
    public function __construct(
        private readonly ShipEngineClient $client,
        private readonly ?ShipEngineService $shipEngineService = null,
    ) {
    }

    /**
     * Compute live carrier shipping rates for a collection of cart items or products.
     *
     * @param  iterable<int, mixed>  $items
     * @param  array{line1?: string, city?: string, state?: string, postal_code?: string, country_code?: string}  $destination
     * @param  array{line1?: string, city?: string, state?: string, postal_code?: string, country_code?: string}|null  $originOverride
     * @return array<int, ShippingOptionDTO>
     */
    public function calculateRatesForCart(
        iterable $items,
        array $destination,
        ?array $originOverride = null
    ): array {
        $products = collect($items)
            ->filter(fn ($item) => ! empty($item->product ?? $item))
            ->map(function ($item) {
                $product = $item->product ?? $item;
                $quantity = (int) ($item->quantity ?? 1);

                return [
                    'product'  => $product,
                    'quantity' => max(1, $quantity),
                ];
            });

        if ($products->isEmpty()) {
            return [];
        }

        // 1. Resolve Package Dimensions & Weights dynamically
        $packages = $this->buildPackagesPayload($products);

        // 2. Resolve Origin & Destination
        $origin = $originOverride ?? $this->resolveOriginAddress($products->first()['product']);
        $dest = $this->normalizeAddress($destination);

        // 3. Query live carrier rates via ShipEngine
        $payload = [
            'validate_address' => 'no_validation',
            'ship_to'          => $dest,
            'ship_from'        => $origin,
            'packages'         => $packages,
        ];

        try {
            $rawRates = $this->client->getRates($payload);
            $normalizedRates = $this->normalizeShipEngineRates($rawRates);

            return $this->mapRatesToShippingOptionDTOs($normalizedRates, $products);
        } catch (Throwable $e) {
            Log::warning('Live carrier shipping calculation failed, utilizing dynamic portal rules fallback: ' . $e->getMessage());

            return $this->getFallbackOptions($products);
        }
    }

    /**
     * Calculate live rates for a single product (used for PDP dynamic shipping estimates).
     *
     * @param  array<string, mixed>  $destination
     * @return array<int, ShippingOptionDTO>
     */
    public function calculateRateForProduct(Product $product, int $quantity, array $destination): array
    {
        return $this->calculateRatesForCart([
            ['product' => $product, 'quantity' => $quantity],
        ], $destination);
    }

    /**
     * Normalize raw ShipEngine carrier rates response into a structured format.
     *
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function normalizeShipEngineRates(array $response): array
    {
        $rateResponse = $response['rate_response'] ?? $response;
        $rates = is_array($rateResponse['rates'] ?? null) ? $rateResponse['rates'] : [];

        return array_map(fn (array $rate) => [
            'rate_id'               => $rate['rate_id'] ?? null,
            'carrier_id'            => $rate['carrier_id'] ?? null,
            'carrier_code'          => $rate['carrier_code'] ?? null,
            'carrier_friendly_name' => $rate['carrier_friendly_name'] ?? null,
            'service_code'          => $rate['service_code'] ?? null,
            'service_type'          => $rate['service_type'] ?? null,
            'shipping_amount'       => (float) ($rate['shipping_amount']['amount'] ?? $rate['shipping_amount'] ?? 0),
            'currency'              => $rate['shipping_amount']['currency'] ?? 'GBP',
            'delivery_days'         => $rate['delivery_days'] ?? null,
            'estimated_delivery_date' => $rate['estimated_delivery_date'] ?? null,
            'package_type'          => $rate['package_type'] ?? null,
            'trackable'             => (bool) ($rate['trackable'] ?? true),
        ], $rates);
    }

    /**
     * Map live carrier rates to ShippingOptionDTO instances with live costs.
     *
     * @param  array<int, array<string, mixed>>  $rates
     * @param  Collection<int, array{product: Product, quantity: int}>  $products
     * @return array<int, ShippingOptionDTO>
     */
    private function mapRatesToShippingOptionDTOs(array $rates, Collection $products): array
    {
        $dtos = [];

        // Check if seller allows free shipping
        $hasFreeShipping = $products->every(fn ($p) => $p['product']->supportsShippingOption('free_shipping'));

        foreach ($rates as $rate) {
            $ruleCode = $this->resolveRuleCodeForCarrierService((string) ($rate['service_type'] ?? ''));
            $dto = ShippingOptionDTO::fromRate($rate, $ruleCode);

            if ($hasFreeShipping && str_contains(strtolower($dto->name), 'standard')) {
                $dto = new ShippingOptionDTO(
                    key: 'free_shipping',
                    name: 'Free Shipping',
                    cost: 0.00,
                    estimatedDelivery: $dto->estimatedDelivery,
                    description: 'Complimentary standard ground delivery.',
                    isAvailable: true,
                    ruleCode: 'FREE_SHIPPING',
                    ruleType: 'Service Group',
                    currency: $dto->currency,
                    rateId: $dto->rateId,
                    carrierCode: $dto->carrierCode,
                    carrierName: $dto->carrierName,
                );
            }

            $dtos[] = $dto;
        }

        return $dtos;
    }

    /**
     * Dynamically build ShipEngine packages from product dimensions and physical weight.
     *
     * @param  Collection<int, array{product: Product, quantity: int}>  $products
     * @return array<int, array<string, mixed>>
     */
    private function buildPackagesPayload(Collection $products): array
    {
        $packages = [];

        foreach ($products as $item) {
            /** @var Product $product */
            $product = $item['product'];
            $qty = $item['quantity'];

            $rawWeight = (float) ($product->weight ?? 0.5);
            $totalWeight = max(0.1, round($rawWeight * $qty, 2));

            $dims = is_array($product->package_dimensions) ? $product->package_dimensions : [];
            $length = max(1.0, (float) ($dims['length'] ?? 10.0));
            $width = max(1.0, (float) ($dims['width'] ?? 10.0));
            $height = max(1.0, (float) ($dims['height'] ?? 5.0));
            $unit = in_array(strtolower((string) ($dims['unit'] ?? '')), ['cm', 'centimeter', 'centimeters'], true)
                ? 'centimeter'
                : 'inch';

            $packages[] = [
                'weight' => [
                    'value' => $totalWeight,
                    'unit'  => 'pound',
                ],
                'dimensions' => [
                    'length' => $length,
                    'width'  => $width,
                    'height' => $height,
                    'unit'   => $unit,
                ],
            ];
        }

        return $packages;
    }

    /**
     * Resolve origin ship_from address from product seller profile or configuration.
     *
     * @return array<string, mixed>
     */
    private function resolveOriginAddress(Product $product): array
    {
        $address = (string) ($product->ship_from_address ?? '');
        $seller = $product->seller;
        $sellerUser = $seller?->user;
        $sellerCountry = $sellerUser?->country ?? 'US';

        $countryCode = 'US';
        $city = 'Dhaka';
        $postal = '1000';
        $line1 = ! empty($address) ? $address : '100 Main St';

        if (! empty($address)) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
            if (! empty($parts)) {
                try {
                    $countryCode = \App\DTOs\ShipEngine\ShipEngineAddress::normalizeCountryCode(end($parts));
                } catch (\Throwable) {
                    try {
                        $countryCode = \App\DTOs\ShipEngine\ShipEngineAddress::normalizeCountryCode($sellerCountry);
                    } catch (\Throwable) {
                        $countryCode = 'US';
                    }
                }
                if (count($parts) >= 2) {
                    $city = $parts[count($parts) - 2];
                }
            }
        } else {
            try {
                $countryCode = \App\DTOs\ShipEngine\ShipEngineAddress::normalizeCountryCode($sellerCountry);
            } catch (\Throwable) {
                $countryCode = 'US';
            }
        }

        return [
            'name'                          => $seller?->shop_name ?? 'Merchant Warehouse',
            'phone'                         => $sellerUser?->mobile_number ?? '555-555-5555',
            'company_name'                  => $seller?->shop_name ?? config('app.name'),
            'address_line1'                 => $line1,
            'city_locality'                 => $city,
            'state_province'                => null,
            'postal_code'                   => $postal,
            'country_code'                  => $countryCode,
            'address_residential_indicator' => 'no',
        ];
    }

    /**
     * Format destination address into standard ShipEngine payload.
     *
     * @param  array<string, mixed>  $destination
     * @return array<string, mixed>
     */
    private function normalizeAddress(array $destination): array
    {
        return [
            'name'                => $destination['name'] ?? 'Customer',
            'phone'               => $destination['phone'] ?? '555-555-5555',
            'address_line1'       => $destination['address_line1'] ?? $destination['line1'] ?? '123 Destination Way',
            'city_locality'       => $destination['city_locality'] ?? $destination['city'] ?? 'London',
            'state_province'      => $destination['state_province'] ?? $destination['state'] ?? 'Greater London',
            'postal_code'         => $destination['postal_code'] ?? $destination['zip'] ?? 'SW1A 1AA',
            'country_code'        => strtoupper((string) ($destination['country_code'] ?? $destination['country'] ?? 'GB')),
            'address_residential_indicator' => 'yes',
        ];
    }

    /**
     * Resolve portal Rule Code for carrier service type.
     */
    private function resolveRuleCodeForCarrierService(string $serviceType): string
    {
        $normalized = strtolower($serviceType);

        if (str_contains($normalized, 'express') || str_contains($normalized, 'overnight') || str_contains($normalized, '2day')) {
            return 'EXPRESS_SHIPPING';
        }
        if (str_contains($normalized, 'international')) {
            return 'INTERNATIONAL_SHIPPING_';
        }
        if (str_contains($normalized, 'heavy')) {
            return 'HEAVY_ITEM_PICKUP';
        }

        return 'STANDARD_SHIPPING';
    }

    /**
     * Fallback to configured dynamic ShipStation rules when live carrier quote is unavailable.
     *
     * @param  Collection<int, array{product: Product, quantity: int}>  $products
     * @return array<int, ShippingOptionDTO>
     */
    private function getFallbackOptions(Collection $products): array
    {
        $rawRules = $this->client->getShippingRules();

        return array_map(function ($rule) {
            return ShippingOptionDTO::fromArray($rule, (float) ($rule['cost'] ?? 0.00));
        }, $rawRules);
    }
}
