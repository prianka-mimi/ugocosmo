<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FulfillAliExpressOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');
        if (! $order) {
            return false;
        }

        $user = $this->user();
        if (! $user) {
            return false;
        }

        $profileId = (int) ($user->profile?->id ?? 0);
        $userId = (int) $user->id;

        // Ensure the seller owns the order
        return ($profileId > 0 && (int) $order->seller_id === $profileId)
            || ($userId > 0 && (int) $order->seller_id === $userId);
    }

    public function rules(): array
    {
        return [
            // Optional override for logistics choice: [item_id => logistics_name]
            'logistics_options' => ['nullable', 'array'],
            'logistics_options.*' => ['string'],

            // Optional explicit SKU mapping: [item_id => sku_attr] or [product_id => sku_attr]
            'sku_options' => ['nullable', 'array'],
            'sku_map' => ['nullable', 'array'],
            'skus' => ['nullable', 'array'],

            // Top-level address overrides
            'line1' => ['nullable', 'string', 'max:500'],
            'address' => ['nullable', 'string', 'max:500'],
            'address1' => ['nullable', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:500'],
            'address2' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:50'],
            'postcode' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:10'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile_no' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],

            // Nested shipping address override
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.line1' => ['sometimes', 'string', 'max:500'],
            'shipping_address.address' => ['sometimes', 'string', 'max:500'],
            'shipping_address.address1' => ['sometimes', 'string', 'max:500'],
            'shipping_address.line2' => ['nullable', 'string', 'max:500'],
            'shipping_address.address2' => ['nullable', 'string', 'max:500'],
            'shipping_address.city' => ['sometimes', 'string', 'max:255'],
            'shipping_address.state' => ['nullable', 'string', 'max:255'],
            'shipping_address.province' => ['nullable', 'string', 'max:255'],
            'shipping_address.county' => ['nullable', 'string', 'max:255'],
            'shipping_address.region' => ['nullable', 'string', 'max:255'],
            'shipping_address.postal_code' => ['sometimes', 'string', 'max:50'],
            'shipping_address.zip' => ['sometimes', 'string', 'max:50'],
            'shipping_address.postcode' => ['sometimes', 'string', 'max:50'],
            'shipping_address.country' => ['sometimes', 'string', 'max:10'],
            'shipping_address.country_code' => ['sometimes', 'string', 'max:10'],
            'shipping_address.phone' => ['nullable', 'string', 'max:50'],
            'shipping_address.name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
