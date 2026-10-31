<?php

namespace App\Http\Requests;

use App\DTOs\ShippingOptionDTO;
use Illuminate\Foundation\Http\FormRequest;

class ProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_type' => $this->normalizeProductType($this->input('product_type')),
            'condition'    => $this->normalizeCondition($this->input('condition')),
        ]);

        if ($this->has('compare_at_price') && ($this->input('compare_at_price') === '' || $this->input('compare_at_price') === null)) {
            $this->merge(['compare_at_price' => null]);
        }

        if ($this->has('weight') && ($this->input('weight') === '' || $this->input('weight') === null)) {
            $this->merge(['weight' => null]);
        }

        if ($this->has('moq') && ($this->input('moq') === '' || $this->input('moq') === null)) {
            $this->merge(['moq' => null]);
        }

        if ($this->has('wholesale_product') && $this->input('wholesale_product') === '') {
            $this->merge(['wholesale_product' => null]);
        }

        if ($this->has('free_shipping_cost') && $this->input('free_shipping_cost') === '') {
            $this->merge(['free_shipping_cost' => null]);
        }

        if ($this->has('local_shipping_cost') && $this->input('local_shipping_cost') === '') {
            $this->merge(['local_shipping_cost' => null]);
        }

        if ($this->has('bulk_pricing')) {
            $this->merge([
                'bulk_pricing' => $this->normalizeBulkPricing($this->input('bulk_pricing')),
            ]);
        }

        if ($this->has('product_variants') && is_string($this->input('product_variants'))) {
            $decoded = json_decode($this->input('product_variants'), true);
            if (is_array($decoded)) {
                $this->merge(['product_variants' => $decoded]);
            }
        }

        if ($this->has('tags') && is_string($this->input('tags'))) {
            $decoded = json_decode($this->input('tags'), true);
            if (is_array($decoded)) {
                $this->merge(['tags' => $decoded]);
            }
        }

        if ($this->has('shipping_options') && is_string($this->input('shipping_options'))) {
            $decoded = json_decode($this->input('shipping_options'), true);
            if (is_array($decoded)) {
                $this->merge(['shipping_options' => $decoded]);
            }
        }

        if ($this->has('package_dimensions') && is_string($this->input('package_dimensions'))) {
            $decoded = json_decode($this->input('package_dimensions'), true);
            if (is_array($decoded)) {
                $this->merge(['package_dimensions' => $decoded]);
            }
        }

        if ($this->has('digital_download_url') && $this->input('digital_download_url') === '') {
            $this->merge(['digital_download_url' => null]);
        }

        if ($this->has('image') && ! $this->hasFile('image') && ($this->input('image') === '' || is_string($this->input('image')))) {
            $this->request->remove('image');
        }

        if ($this->has('video') && ! $this->hasFile('video') && ($this->input('video') === '' || is_string($this->input('video')))) {
            $this->request->remove('video');
        }
    }

    protected function normalizeProductType(mixed $raw): string
    {
        if ($raw === null) {
            return 'physical';
        }

        $str = strtolower(trim((string) $raw));

        if ($str === '' || $str === 'null' || $str === 'undefined' || $str === '0' || $str === 'false') {
            return 'physical';
        }

        if (
            str_contains($str, 'digital') ||
            str_contains($str, 'virtual') ||
            str_contains($str, 'download') ||
            str_contains($str, 'service') ||
            $str === '1' ||
            $str === 'true'
        ) {
            return 'digital';
        }

        return 'physical';
    }

    protected function normalizeCondition(mixed $raw): string
    {
        if ($raw === null) {
            return 'new';
        }

        $str = strtolower(trim((string) $raw));

        if ($str === '' || $str === 'null' || $str === 'undefined') {
            return 'new';
        }

        if (str_contains($str, 'refurb')) {
            return 'refurbished';
        }

        if (str_contains($str, 'used') || str_contains($str, 'second')) {
            return 'used';
        }

        return 'new';
    }

    protected function normalizeBulkPricing(mixed $raw): ?array
    {
        if (empty($raw)) {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (! is_array($raw)) {
            return null;
        }

        // Support columnar arrays: ['min_quantity' => [...], 'price_per_unit' => [...]]
        if (isset($raw['min_quantity']) && is_array($raw['min_quantity']) && isset($raw['price_per_unit']) && is_array($raw['price_per_unit'])) {
            $paired = [];
            $count = max(count($raw['min_quantity']), count($raw['price_per_unit']));
            for ($i = 0; $i < $count; $i++) {
                $entry = [];
                if (isset($raw['min_quantity'][$i])) {
                    $entry['min_quantity'] = is_numeric($raw['min_quantity'][$i]) ? (int) $raw['min_quantity'][$i] : $raw['min_quantity'][$i];
                }
                if (isset($raw['price_per_unit'][$i])) {
                    $entry['price_per_unit'] = is_numeric($raw['price_per_unit'][$i]) ? (float) $raw['price_per_unit'][$i] : $raw['price_per_unit'][$i];
                }
                if (! empty($entry)) {
                    $paired[] = $entry;
                }
            }
            return ! empty($paired) ? $paired : null;
        }

        // Check if items are already well-formed pairs
        $allPaired = true;
        foreach ($raw as $item) {
            if (! is_array($item) || ! isset($item['min_quantity']) || ! isset($item['price_per_unit'])) {
                $allPaired = false;
                break;
            }
        }

        if ($allPaired && ! empty($raw)) {
            return array_values($raw);
        }

        // Handle split arrays caused by multipart/form-data empty brackets (e.g. bulk_pricing[][min_quantity])
        $minQuantities = [];
        $prices = [];
        $otherPairs = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['min_quantity']) && isset($item['price_per_unit'])) {
                $otherPairs[] = [
                    'min_quantity' => is_numeric($item['min_quantity']) ? (int) $item['min_quantity'] : $item['min_quantity'],
                    'price_per_unit' => is_numeric($item['price_per_unit']) ? (float) $item['price_per_unit'] : $item['price_per_unit'],
                ];
            } elseif (isset($item['min_quantity'])) {
                $minQuantities[] = is_numeric($item['min_quantity']) ? (int) $item['min_quantity'] : $item['min_quantity'];
            } elseif (isset($item['price_per_unit'])) {
                $prices[] = is_numeric($item['price_per_unit']) ? (float) $item['price_per_unit'] : $item['price_per_unit'];
            }
        }

        $paired = [];
        $count = max(count($minQuantities), count($prices));
        for ($i = 0; $i < $count; $i++) {
            $entry = [];
            if (isset($minQuantities[$i])) {
                $entry['min_quantity'] = $minQuantities[$i];
            }
            if (isset($prices[$i])) {
                $entry['price_per_unit'] = $prices[$i];
            }
            if (! empty($entry)) {
                $paired[] = $entry;
            }
        }

        $result = array_merge($otherPairs, $paired);

        return ! empty($result) ? $result : null;
    }

    public function rules(): array
    {
        return [
            // ── 1. Basic Information ───────────────────────────────
            'title'                => ['required', 'string', 'max:255'],
            'description'          => ['nullable', 'string', 'max:5000'],
            'sku'                  => ['nullable', 'string', 'max:100'],
            'price'                => ['required', 'numeric', 'min:0'],
            'compare_at_price'     => ['nullable', 'numeric', 'min:0', 'gt:price'],
            'currency'             => ['nullable', 'string', 'max:10'],
            'stock_quantity'       => ['required', 'integer', 'min:0'],
            'category_id'          => ['required', 'integer', 'exists:product_categories,id'],
            'product_type'         => ['nullable', 'string', 'in:physical,digital'],
            'condition'            => ['nullable', 'string', 'in:new,used,refurbished'],
            'brand'                => ['nullable', 'string', 'max:255'],
            'ean_upc'              => ['nullable', 'string', 'max:100'],
            'tags'                 => ['nullable', 'array', 'max:15'],
            'tags.*'               => ['string', 'max:30'],

            // ── 2. Details ─────────────────────────────────────────
            'specifications'       => ['nullable', 'string', 'max:10000'],
            'features_benefits'    => ['nullable', 'string', 'max:10000'],
            'weight'               => ['nullable', 'numeric', 'min:0'],
            'wholesale_product'    => ['nullable', 'boolean'],
            'moq'                  => ['nullable', 'integer', 'min:1'],
            'bulk_pricing'                      => ['nullable', 'array'],
            'bulk_pricing.*.min_quantity'       => ['required_with:bulk_pricing', 'integer', 'min:1'],
            'bulk_pricing.*.price_per_unit'     => ['required_with:bulk_pricing', 'numeric', 'min:0'],
            'product_variants'                          => ['nullable', 'array'],
            'product_variants.*.name'                   => ['required_with:product_variants', 'string', 'max:100'],
            'product_variants.*.type'                   => ['nullable', 'string', 'max:100'],
            'product_variants.*.options'                => ['required_with:product_variants', 'array'],
            'product_variants.*.options.*.value'        => ['required_with:product_variants', 'string', 'max:100'],
            'product_variants.*.options.*.priceModifier'    => ['nullable', 'numeric'],
            'product_variants.*.options.*.price_adjustment' => ['nullable', 'numeric'],
            'product_variants.*.options.*.stockQuantity'    => ['nullable', 'integer', 'min:0'],
            'product_variants.*.options.*.stock'            => ['nullable', 'integer', 'min:0'],

            // ── 3. Shipping ────────────────────────────────────────
            'local_location'             => ['nullable', 'string', 'max:255'],
            'ship_from_address'          => ['required', 'string', 'max:500'],
            'shipping_options'           => ['nullable', 'array', 'max:50'],
            'shipping_options.*'         => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\s]+$/'],
            'allowed_shipping_options'   => ['nullable', 'array', 'max:50'],
            'allowed_shipping_options.*' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\s]+$/'],
            'free_shipping_enabled'              => ['nullable', 'boolean'],
            'free_shipping_cost'                 => ['nullable', 'numeric', 'min:0'],
            'free_shipping_estimated_delivery'   => ['nullable', 'string', 'max:100'],
            'local_shipping_enabled'             => ['nullable', 'boolean'],
            'local_shipping_cost'                => ['nullable', 'numeric', 'min:0'],
            'local_shipping_estimated_delivery'  => ['nullable', 'string', 'max:100'],
            'package_dimensions'         => ['nullable', 'array'],
            'package_dimensions.length'  => ['nullable', 'numeric', 'min:0'],
            'package_dimensions.width'   => ['nullable', 'numeric', 'min:0'],
            'package_dimensions.height'  => ['nullable', 'numeric', 'min:0'],
            'package_dimensions.unit'    => ['nullable', 'string', 'in:cm,inches,inch,centimeter,centimeters'],

            // ── 4. Media & Status ──────────────────────────────────
            'image'                => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp'],
            'images'               => ['nullable', 'array', 'max:10'],
            'images.*'             => ['image', 'mimes:jpeg,png,jpg,gif,webp'],
            'video'                => ['nullable', 'file', 'mimes:mp4,mov,webm', 'max:102400'],
            'digital_download_url' => ['nullable', 'url', 'max:1000'],
            'is_active'            => ['nullable', 'boolean'],

            // ── Unused / Internal (kept for backward compatibility) ─
            // 'allowed_shipping_options'           => ['nullable', 'array'],
            // 'allowed_shipping_options.*'         => ['nullable', 'string'],
            // 'aliexpress_shipping_cost'           => ['nullable', 'numeric', 'min:0'],
            // 'estimated_delivery_time'            => ['nullable', 'string', 'max:100'],
            // 'shipping_cost'                      => ['nullable', 'numeric', 'min:0'],
            // 'shipping_tiers'                     => ['nullable', 'array'],
            // 'shipping_tiers.standard_shipping_cost'          => ['nullable', 'numeric', 'min:0'],
            // 'shipping_tiers.express_shipping_cost'           => ['nullable', 'numeric', 'min:0'],
            // 'shipping_tiers.international_shipping_cost'     => ['nullable', 'numeric', 'min:0'],
            // 'shipping_tiers.free_shipping_enabled'           => ['nullable', 'boolean'],
            // 'shipping_tiers.free_shipping_cost'              => ['nullable', 'numeric', 'min:0'],
            // 'shipping_tiers.free_shipping_estimated_delivery'=> ['nullable', 'string', 'max:100'],
            // 'shipping_tiers.local_shipping_enabled'          => ['nullable', 'boolean'],
            // 'shipping_tiers.local_shipping_cost'             => ['nullable', 'numeric', 'min:0'],
            // 'shipping_tiers.local_shipping_estimated_delivery'=> ['nullable', 'string', 'max:100'],
            // 'standard_shipping_cost'             => ['nullable', 'numeric', 'min:0'],
            // 'express_shipping_cost'              => ['nullable', 'numeric', 'min:0'],
            // 'international_shipping_cost'        => ['nullable', 'numeric', 'min:0'],
            // 'handling_time'                      => ['nullable', 'string', 'max:100'],
            // 'estimated_delivery'                 => ['nullable', 'string', 'max:100'],
            // 'shipping_info'                      => ['nullable', 'string', 'max:500'],
            // 'shipping_strategy'                  => ['nullable', 'string', 'in:api_tracked,manual'],
        ];
    }
}
