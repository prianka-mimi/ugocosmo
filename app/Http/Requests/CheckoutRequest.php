<?php

namespace App\Http\Requests;

use App\DTOs\ShippingOptionDTO;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address_id' => ['nullable', 'integer', 'exists:saved_addresses,id'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.name' => ['nullable', 'string', 'max:255'],
            'shipping_address.line1' => ['nullable', 'string', 'max:255'],
            'shipping_address.address_line1' => ['nullable', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:100'],
            'shipping_address.city_locality' => ['nullable', 'string', 'max:100'],
            'shipping_address.state' => ['nullable', 'string', 'max:100'],
            'shipping_address.state_province' => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:50'],
            'shipping_address.zip' => ['nullable', 'string', 'max:50'],
            'shipping_address.country' => ['nullable', 'string', 'max:10'],
            'shipping_address.country_code' => ['nullable', 'string', 'max:10'],
            'shipping_address.phone' => ['nullable', 'string', 'max:50'],
            'shipping_method' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\s]+$/'],
            'rate_id' => ['nullable', 'string', 'max:255'],
            'selected_rate_id' => ['nullable', 'string', 'max:255'],
            'carrier_id' => ['nullable', 'string', 'max:255'],
            'carrier_code' => ['nullable', 'string', 'max:255'],
            'service_code' => ['nullable', 'string', 'max:255'],
            'service_name' => ['nullable', 'string', 'max:255'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'selected_rate_snapshot' => ['nullable', 'array'],
            'cart_item_ids' => ['nullable', 'array'],
            'cart_item_ids.*' => ['integer', 'exists:cart_items,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'success_url' => ['nullable', 'url', 'max:500'],
            'cancel_url' => ['nullable', 'url', 'max:500'],
        ];
    }
}
