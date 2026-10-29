<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ShippingOptionDTO
{
    public const STANDARD_SHIPPING = 'standard_shipping';
    public const EXPRESS_SHIPPING = 'express_shipping';
    public const FREE_SHIPPING = 'free_shipping';
    public const LOCAL_SHIPPING = 'local_shipping';
    public const LOCAL_PICKUP = 'local_pickup';
    public const INTERNATIONAL_SHIPPING = 'international_shipping';
    public const MULTI_ITEM_PICKUP = 'multi_item_pickup';
    public const HEAVY_ITEM_PICKUP = 'heavy_item_pickup';

    public function __construct(
        public string $key,
        public string $name,
        public float $cost = 0.00,
        public string $estimatedDelivery = 'Varies by destination',
        public string $description = 'Standard delivery service.',
        public bool $isAvailable = true,
        public ?string $ruleCode = null,
        public ?string $ruleId = null,
        public ?string $ruleType = null,
        public string $currency = 'USD',
        public ?string $rateId = null,
        public ?string $carrierCode = null,
        public ?string $carrierName = null,
    ) {
    }

    /**
     * Pure data mapper from arbitrary dynamic API response (ShipStation, ShipEngine, Database).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, float $cost = 0.00): self
    {
        $code = (string) ($data['rule_code'] ?? $data['ruleCode'] ?? $data['code'] ?? '');
        $rawKey = ! empty($data['key']) ? (string) $data['key'] : ($code ?: (string) ($data['name'] ?? 'custom_shipping'));
        $key = self::normalizeKey($rawKey) ?? 'standard_shipping';

        $name = ! empty($data['name'])
            ? (string) $data['name']
            : ucwords(str_replace('_', ' ', $key));

        return new self(
            key: $key,
            name: $name,
            cost: isset($data['cost']) && is_numeric($data['cost']) ? (float) $data['cost'] : $cost,
            estimatedDelivery: (string) ($data['estimated_delivery'] ?? $data['estimatedDelivery'] ?? 'Same day or next business day'),
            description: (string) ($data['description'] ?? 'Automated shipping option from carrier rules.'),
            isAvailable: isset($data['is_available']) ? (bool) $data['is_available'] : true,
            ruleCode: $code ?: strtoupper($key),
            ruleId: isset($data['rule_id']) ? (string) $data['rule_id'] : (isset($data['ruleId']) ? (string) $data['ruleId'] : null),
            ruleType: (string) ($data['rule_type'] ?? $data['ruleType'] ?? 'Service Group'),
            currency: (string) ($data['currency'] ?? 'USD'),
            rateId: isset($data['rate_id']) ? (string) $data['rate_id'] : null,
            carrierCode: isset($data['carrier_code']) ? (string) $data['carrier_code'] : null,
            carrierName: isset($data['carrier_name']) || isset($data['carrier_friendly_name'])
                ? (string) ($data['carrier_name'] ?? $data['carrier_friendly_name'])
                : null,
        );
    }

    /**
     * Build DTO dynamically from a live carrier rate quote.
     *
     * @param  array<string, mixed>  $rate
     */
    public static function fromRate(array $rate, ?string $ruleCode = null): self
    {
        $rawType = (string) ($rate['service_type'] ?? $rate['service_code'] ?? 'standard_shipping');
        $key = self::normalizeKey($rawType) ?? 'standard_shipping';

        return new self(
            key: $key,
            name: (string) ($rate['service_type'] ?? ucwords(str_replace('_', ' ', $key))),
            cost: round((float) ($rate['shipping_amount'] ?? 0.00), 2),
            estimatedDelivery: ! empty($rate['delivery_days'])
                ? "{$rate['delivery_days']} business days"
                : ((string) ($rate['estimated_delivery_date'] ?? '2-5 business days')),
            description: sprintf(
                '%s via %s (%s)',
                $rate['service_type'] ?? 'Standard Ground',
                $rate['carrier_friendly_name'] ?? $rate['carrier_code'] ?? 'Carrier',
                $rate['currency'] ?? 'USD'
            ),
            isAvailable: true,
            ruleCode: $ruleCode ?: strtoupper($key),
            currency: (string) ($rate['currency'] ?? 'USD'),
            rateId: isset($rate['rate_id']) ? (string) $rate['rate_id'] : null,
            carrierCode: isset($rate['carrier_code']) ? (string) $rate['carrier_code'] : null,
            carrierName: (string) ($rate['carrier_friendly_name'] ?? $rate['carrier_code'] ?? ''),
        );
    }

    /**
     * Backward-compatible factory that queries live rules dynamically without any hardcoded match blocks.
     */
    public static function make(string|array $keyOrData, float $cost = 0.00, bool $isAvailable = true): self
    {
        if (is_array($keyOrData)) {
            return self::fromArray($keyOrData, $cost);
        }

        $canonical = self::normalizeKey($keyOrData) ?? $keyOrData;
        $rules = self::getDynamicRulesMap();

        if (isset($rules[$canonical])) {
            return self::fromArray($rules[$canonical], $cost);
        }

        return new self(
            key: $canonical,
            name: ucwords(str_replace('_', ' ', $canonical)),
            cost: round(max(0.00, $cost), 2),
            estimatedDelivery: 'Varies by destination',
            description: 'Custom seller shipping option.',
            isAvailable: $isAvailable,
            ruleCode: strtoupper($canonical),
        );
    }

    /**
     * Normalize shorthand, rule_code, or snake_case option key dynamically.
     */
    public static function normalizeKey(string $key): ?string
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower($trimmed);
        $normalized = rtrim($normalized, '_');

        // Seamlessly unify legacy local_pickup to local_shipping (Local Delivery) without breaking existing app models
        if ($normalized === 'local_pickup') {
            return self::LOCAL_SHIPPING;
        }

        // Check if matches a known ShipStation rule in cache
        $rules = self::getDynamicRulesMap();
        if (isset($rules[$normalized])) {
            return $rules[$normalized]['key'];
        }

        if (preg_match('/^[a-z0-9_\-\s]+$/i', $normalized)) {
            $slug = str_replace([' ', '-'], '_', $normalized);
            return rtrim($slug, '_');
        }

        return null;
    }

    public static function isValid(string $key): bool
    {
        return self::normalizeKey($key) !== null;
    }

    /**
     * Retrieve cached map of ShipEngine / ShipStation rules indexed by key, rule_code, and rule_id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getDynamicRulesMap(): array
    {
        try {
            if (function_exists('app') && app()->bound(\App\Services\ShipEngineClient::class)) {
                $rules = app(\App\Services\ShipEngineClient::class)->getShippingRules();
                $map = [];
                foreach ($rules as $r) {
                    if (! empty($r['key'])) {
                        $map[strtolower(trim((string) $r['key']))] = $r;
                    }
                    if (! empty($r['rule_code'])) {
                        $map[strtolower(trim((string) $r['rule_code']))] = $r;
                    }
                    if (! empty($r['rule_id'])) {
                        $map[strtolower(trim((string) $r['rule_id']))] = $r;
                    }
                }
                return $map;
            }
        } catch (\Throwable) {
            // Silently fall through
        }

        return [];
    }

    public function toArray(): array
    {
        return [
            'rule_code' => $this->ruleCode ?? strtoupper($this->key),
            'rule_id' => $this->ruleId,
            'rule_type' => $this->ruleType ?? 'Service Group',
            'key' => $this->key,
            'name' => $this->name,
            'cost' => $this->cost,
            'currency' => $this->currency,
            'estimated_delivery' => $this->estimatedDelivery,
            'description' => $this->description,
            'is_available' => $this->isAvailable,
            'rate_id' => $this->rateId,
            'carrier_code' => $this->carrierCode,
            'carrier_name' => $this->carrierName,
        ];
    }
}
