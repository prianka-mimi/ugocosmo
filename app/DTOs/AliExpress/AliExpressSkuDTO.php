<?php

namespace App\DTOs\AliExpress;

/**
 * A single SKU/variant of an AliExpress product.
 */
final readonly class AliExpressSkuDTO
{
    public function __construct(
        public string $skuId,
        public ?string $skuCode,
        public ?string $skuPrice,
        public int $stock,
        /** @var array<int, array{name: string, value: string, image?: ?string, property_id?: int|string, value_id?: int|string}> */
        public array $properties,
        public ?string $skuAttr = null,
        public ?string $image = null,
        public ?string $originalPrice = null,
        public ?string $currency = 'USD',
    ) {}

    /**
     * Parse a SKU from aliexpress.ds.product.get response.
     *
     * Official SKU fields:
     *   sku_id, sku_attr, offer_sale_price, sku_price, sku_available_stock,
     *   currency_code, ae_sku_property_dtos[] (direct array)
     */
    public static function fromDsResponse(array $sku): self
    {
        // Price: prefer offer_sale_price (promotional), fallback to sku_price
        $price = isset($sku['offer_sale_price']) && $sku['offer_sale_price'] !== ''
            ? (string) $sku['offer_sale_price']
            : (isset($sku['sku_price']) ? (string) $sku['sku_price'] : null);
        $originalPrice = isset($sku['sku_price']) && $sku['sku_price'] !== ''
            ? (string) $sku['sku_price']
            : $price;
        $currency = ! empty($sku['currency_code']) ? (string) $sku['currency_code'] : 'USD';

        // ae_sku_property_dtos is a direct array per official docs
        $propList = $sku['ae_sku_property_dtos'] ?? [];
        if (! array_is_list($propList) && ! empty($propList)) {
            $propList = [$propList];
        }

        $properties = [];
        $skuAttrParts = [];
        $skuImage = $sku['sku_image'] ?? $sku['sku_image_url'] ?? $sku['image'] ?? null;

        foreach ($propList as $prop) {
            if (! is_array($prop)) {
                continue;
            }
            $name = (string) ($prop['sku_property_name'] ?? '');
            $customName = trim((string) ($prop['property_value_definition_name'] ?? ''));
            $standardName = trim((string) ($prop['sku_property_value'] ?? ''));
            $propImage = $prop['sku_image'] ?? $prop['property_value_image'] ?? $prop['sku_image_url'] ?? null;

            if ($skuImage === null && $propImage !== null) {
                $skuImage = $propImage;
            }

            // Prefer descriptive name over arbitrary numbers like "1", "2", "3"
            if ($customName !== '' && $standardName !== '' && is_numeric($customName) && ! is_numeric($standardName)) {
                $value = $standardName;
            } elseif ($customName !== '') {
                $value = $customName;
            } else {
                $value = $standardName;
            }

            $propertyId = $prop['sku_property_id'] ?? null;
            $valueId = $prop['property_value_id'] ?? null;

            // If value is still numeric or empty, check if sku_attr or sku_code has a descriptive name (#Name)
            if (($value === '' || is_numeric($value)) && ! empty($sku['sku_attr']) && $propertyId !== null && $valueId !== null) {
                if (preg_match('/(?:^|;)'.preg_quote((string) $propertyId, '/').':'.preg_quote((string) $valueId, '/').'#([^;]+)/', (string) $sku['sku_attr'], $mAttr)) {
                    $attrVal = trim($mAttr[1]);
                    if ($attrVal !== '' && ! is_numeric($attrVal)) {
                        $value = $attrVal;
                    }
                }
            }

            if ($name !== '' || $value !== '') {
                $propEntry = [
                    'name' => $name,
                    'value' => $value,
                    'property_id' => $propertyId,
                    'value_id' => $valueId,
                ];
                if ($propImage) {
                    $propEntry['image'] = (string) $propImage;
                }
                $properties[] = $propEntry;
            }

            if ($propertyId !== null && $valueId !== null) {
                $skuAttrParts[] = $propertyId.':'.$valueId.($value !== '' ? '#'.$value : '');
            }
        }

        return new self(
            skuId: (string) ($sku['sku_id'] ?? ''),
            skuCode: isset($sku['sku_attr']) ? (string) $sku['sku_attr'] : null,
            skuPrice: $price,
            stock: (int) ($sku['sku_available_stock'] ?? 0),
            properties: $properties,
            skuAttr: $skuAttrParts !== [] ? implode(';', $skuAttrParts) : (isset($sku['sku_attr']) ? (string) $sku['sku_attr'] : null),
            image: $skuImage !== null ? (string) $skuImage : null,
            originalPrice: $originalPrice,
            currency: $currency,
        );
    }

    /**
     * Parse a SKU entry from the merchant product query response
     * (aeop_ae_product_skus[]. Stock key varies across tiers:
     * ipm_sku_stock / sku_available_stock / sku_stock).
     */
    public static function fromMerchantResponse(array $sku): self
    {
        $stock = 0;
        foreach (['ipm_sku_stock', 'sku_available_stock', 'sku_stock', 'stock'] as $key) {
            if (isset($sku[$key]) && is_numeric($sku[$key])) {
                $stock = (int) $sku[$key];
                break;
            }
        }

        $properties = [];
        $skuAttrParts = [];
        $skuImage = $sku['sku_image'] ?? $sku['sku_image_url'] ?? $sku['image'] ?? null;

        foreach ((array) ($sku['aeop_sku_property'] ?? []) as $property) {
            if (! is_array($property)) {
                continue;
            }

            $name = (string) ($property['sku_property_name'] ?? $property['property_value_definition_name'] ?? '');
            $customName = trim((string) ($property['property_value_definition_name'] ?? ''));
            $standardName = trim((string) ($property['sku_property_value'] ?? ''));
            $propImage = $property['sku_image'] ?? $property['sku_image_url'] ?? null;

            if ($skuImage === null && $propImage !== null) {
                $skuImage = $propImage;
            }

            if ($customName !== '' && $standardName !== '' && is_numeric($customName) && ! is_numeric($standardName)) {
                $value = $standardName;
            } elseif ($customName !== '') {
                $value = $customName;
            } else {
                $value = $standardName;
            }

            $propertyId = $property['sku_property_id'] ?? $property['id'] ?? null;
            $valueId = $property['sku_property_value_id'] ?? $property['value_id'] ?? null;

            if (($value === '' || is_numeric($value)) && ! empty($sku['sku_code']) && $propertyId !== null && $valueId !== null) {
                if (preg_match('/(?:^|;)'.preg_quote((string) $propertyId, '/').':'.preg_quote((string) $valueId, '/').'#([^;]+)/', (string) $sku['sku_code'], $mAttr)) {
                    $attrVal = trim($mAttr[1]);
                    if ($attrVal !== '' && ! is_numeric($attrVal)) {
                        $value = $attrVal;
                    }
                }
            }

            if ($name !== '' || $value !== '') {
                $propEntry = ['name' => $name, 'value' => $value];
                if ($propImage) {
                    $propEntry['image'] = (string) $propImage;
                }
                $properties[] = $propEntry;
            }

            $valueName = $value !== '' ? $value : $name;
            if ($propertyId !== null && $valueId !== null) {
                $skuAttrParts[] = $propertyId.':'.$valueId.($valueName !== '' ? '#'.$valueName : '');
            }
        }

        $skuAttr = $skuAttrParts !== [] ? implode(';', $skuAttrParts) : null;

        return new self(
            skuId: (string) ($sku['sku_id'] ?? ''),
            skuCode: isset($sku['sku_code']) ? (string) $sku['sku_code'] : null,
            skuPrice: isset($sku['sku_price']) ? (string) $sku['sku_price'] : null,
            stock: $stock,
            properties: $properties,
            skuAttr: $skuAttr,
            image: $skuImage !== null ? (string) $skuImage : null,
        );
    }

    /**
     * Parse a SKU entry from the dropshipper inventory API
     * (aliexpress.offer.ds.product.simplequery).
     *
     * Stock precedence follows the platform's documented rule: `ipm_sku_stock`
     * is authoritative, then `s_k_u_available_stock`, then the `sku_stock`
     * boolean. The SKU identifier arrives as `id` on this endpoint (and as
     * `sku_id` on ds.product.get), so both keys are read.
     */
    public static function fromInventoryResponse(array $sku): self
    {
        $stock = 0;

        foreach (['ipm_sku_stock', 's_k_u_available_stock', 'sku_available_stock'] as $key) {
            if (isset($sku[$key]) && is_numeric($sku[$key])) {
                $stock = (int) $sku[$key];
                break;
            }
        }

        // Fall back to the boolean "any stock" flag when no numeric stock
        // is present. AliExpress treats true as a healthy 999-unit buffer.
        if ($stock === 0 && array_key_exists('sku_stock', $sku)) {
            $stock = filter_var($sku['sku_stock'], FILTER_VALIDATE_BOOLEAN) ? 999 : 0;
        }

        $price = isset($sku['offer_sale_price']) && $sku['offer_sale_price'] !== ''
            ? (string) $sku['offer_sale_price']
            : (isset($sku['sku_price']) ? (string) $sku['sku_price'] : null);

        $properties = [];
        $skuAttrParts = [];
        $skuImage = $sku['sku_image'] ?? $sku['sku_image_url'] ?? $sku['image'] ?? null;

        $propertyContainer = $sku['aeop_s_k_u_property_list'] ?? $sku['aeop_sku_property'] ?? [];
        if (is_array($propertyContainer) && isset($propertyContainer['aeop_sku_property'])) {
            $propertyContainer = $propertyContainer['aeop_sku_property'];
        }
        if (! is_array($propertyContainer) && ! empty($propertyContainer)) {
            $propertyContainer = [$propertyContainer];
        }

        foreach ((array) $propertyContainer as $property) {
            if (! is_array($property)) {
                continue;
            }

            $name = (string) ($property['property_value_definition_name'] ?? $property['sku_property_value'] ?? '');
            $customName = trim((string) ($property['property_value_definition_name'] ?? ''));
            $standardName = trim((string) ($property['sku_property_value'] ?? ''));
            $label = (string) ($property['sku_property_name'] ?? '');
            $propImage = $property['sku_image'] ?? $property['sku_image_url'] ?? null;

            if ($skuImage === null && $propImage !== null) {
                $skuImage = $propImage;
            }

            if ($customName !== '' && $standardName !== '' && is_numeric($customName) && ! is_numeric($standardName)) {
                $val = $standardName;
            } else {
                $val = $name;
            }

            if ($val !== '' || $label !== '') {
                $propEntry = ['name' => $label !== '' ? $label : 'Option', 'value' => $val];
                if ($propImage) {
                    $propEntry['image'] = (string) $propImage;
                }
                $properties[] = $propEntry;
            }

            $propertyId = $property['sku_property_id'] ?? null;
            $valueId = $property['property_value_id'] ?? null;

            if ($propertyId !== null && $valueId !== null) {
                $skuAttrParts[] = $propertyId.':'.$valueId.($val !== '' ? '#'.$val : '');
            }
        }

        return new self(
            skuId: (string) ($sku['id'] ?? $sku['sku_id'] ?? ''),
            skuCode: isset($sku['sku_attr']) ? (string) $sku['sku_attr'] : null,
            skuPrice: $price,
            stock: $stock,
            properties: $properties,
            skuAttr: $skuAttrParts !== [] ? implode(';', $skuAttrParts) : (isset($sku['sku_attr']) ? (string) $sku['sku_attr'] : null),
            image: $skuImage !== null ? (string) $skuImage : null,
        );
    }

    /**
     * Copy of this SKU carrying a refreshed stock level.
     *
     * Used to overlay real-time inventory from the SKU/inventory API onto
     * SKUs parsed from the product-detail response without mutating the
     * original immutable DTO.
     */
    public function withStock(int $stock): self
    {
        return new self(
            skuId: $this->skuId,
            skuCode: $this->skuCode,
            skuPrice: $this->skuPrice,
            stock: $stock,
            properties: $this->properties,
            skuAttr: $this->skuAttr,
            image: $this->image,
            originalPrice: $this->originalPrice,
            currency: $this->currency,
        );
    }

    /**
     * Map to the local product_variants entry shape used by ProductResource.
     */
    public function toLocalVariant(): array
    {
        $options = array_map(
            fn (array $property) => [
                'name' => $property['name'],
                'value' => $property['value'],
                'image' => $property['image'] ?? null,
            ],
            $this->properties
        );

        $stock = $this->stock > 0 ? $this->stock : 100;

        return [
            'name' => $this->skuCode ?: ('SKU '.$this->skuId),
            'sku' => $this->skuCode ?? $this->skuId,
            'sku_attr' => $this->skuAttr,
            'price' => $this->skuPrice,
            'original_price' => $this->originalPrice ?? $this->skuPrice,
            'image' => $this->image,
            'stock' => $stock,
            'stockQuantity' => $stock,
            'options' => $options,
        ];
    }
}
