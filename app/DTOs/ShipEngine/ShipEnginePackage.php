<?php

namespace App\DTOs\ShipEngine;

use App\Models\Product;

/**
 * Immutable package normalized to the ShipEngine / ShipStation package
 * contract (weight.value + weight.unit, optional dimensions).
 *
 * products.weight has no unit column, so the weight unit comes from
 * config('services.shipengine.weight_unit') (default: ounce).
 * products.package_dimensions stores {length, width, height, unit}
 * where unit is "cm" or "inches" (product form) — normalized to the
 * ShipEngine units "centimeter" / "inch".
 */
final readonly class ShipEnginePackage
{
    public function __construct(
        public float $weightValue,
        public string $weightUnit = 'ounce',
        public ?float $length = null,
        public ?float $width = null,
        public ?float $height = null,
        public string $dimensionUnit = 'inch',
        public string $packageCode = 'package',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'package_code' => $this->packageCode,
            'weight' => [
                'value' => $this->weightValue,
                'unit' => $this->weightUnit,
            ],
        ];

        if ($this->length !== null && $this->width !== null && $this->height !== null) {
            $payload['dimensions'] = [
                'length' => $this->length,
                'width' => $this->width,
                'height' => $this->height,
                'unit' => $this->dimensionUnit,
            ];
        }

        return $payload;
    }

    /**
     * Build a package from a product (quantity folded into the weight).
     * Falls back to configured defaults when weight or dimensions are missing.
     */
    public static function fromProduct(Product $product, int $quantity, array $config = []): self
    {
        $defaultWeightValue = (float) ($config['default_weight_value'] ?? config('shipengine.default_weight_value', config('services.shipengine.default_weight_value', 1.0)));
        $defaultWeightUnit = (string) ($config['default_weight_unit'] ?? config('shipengine.default_weight_unit', $config['weight_unit'] ?? config('services.shipengine.weight_unit', 'pound')));

        $productWeight = (float) $product->weight;
        $weightValue = $productWeight > 0 ? $productWeight : $defaultWeightValue;
        $weightUnit = (string) ($config['weight_unit'] ?? $defaultWeightUnit);

        $dimensions = is_array($product->package_dimensions) ? $product->package_dimensions : [];
        $defaultDims = (array) ($config['default_dimensions'] ?? config('shipengine.default_dimensions', config('services.shipengine.default_dimensions', [
            'length' => 10.0,
            'width' => 5.0,
            'height' => 5.0,
            'unit' => 'inch',
        ])));

        $length = isset($dimensions['length']) && is_numeric($dimensions['length'])
            ? (float) $dimensions['length']
            : (isset($defaultDims['length']) ? (float) $defaultDims['length'] : 10.0);

        $width = isset($dimensions['width']) && is_numeric($dimensions['width'])
            ? (float) $dimensions['width']
            : (isset($defaultDims['width']) ? (float) $defaultDims['width'] : 5.0);

        $height = isset($dimensions['height']) && is_numeric($dimensions['height'])
            ? (float) $dimensions['height']
            : (isset($defaultDims['height']) ? (float) $defaultDims['height'] : 5.0);

        $dimUnit = (string) ($dimensions['unit'] ?? ($defaultDims['unit'] ?? 'inch'));

        return new self(
            weightValue: round($weightValue * max(1, $quantity), 2),
            weightUnit: $weightUnit,
            length: $length,
            width: $width,
            height: $height,
            dimensionUnit: self::normalizeDimensionUnit($dimUnit),
        );
    }

    protected static function normalizeDimensionUnit(string $unit): string
    {
        return match (strtolower($unit)) {
            'cm', 'centimeter', 'centimeters' => 'centimeter',
            'in', 'inch', 'inches' => 'inch',
            default => 'inch',
        };
    }
}
