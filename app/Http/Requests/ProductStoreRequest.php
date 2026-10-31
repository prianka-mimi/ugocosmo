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
        if ($this->has('product_type') && $this->input('product_type') !== null) {
            $this->merge([
                'product_type' => strtolower(trim((string) $this->input('product_type'))),
            ]);
        } else {
            $this->merge([
                'product_type' => 'physical',
            ]);
        }

        if ($this->has('condition') && $this->input('condition') !== null) {
            $this->merge([
                'condition' => strtolower(trim((string) $this->input('condition'))),
            ]);
        } else {
            $this->merge([
                'condition' => 'new',
            ]);
        }
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
            'product_type'         => ['required', 'string', 'in:physical,digital'],
            'condition'            => ['required', 'string', 'in:new,used,refurbished'],
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
