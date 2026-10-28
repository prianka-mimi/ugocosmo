<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateShippingAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // shipment ownership is enforced via ShipmentPolicy
    }

    protected function prepareForValidation(): void
    {
        $address = $this->input('address');
        if (is_string($address)) {
            $decoded = json_decode($address, true);
            if (is_array($decoded)) {
                $address = $decoded;
            }
        }

        if (is_array($address)) {
            $this->merge([
                'address' => [
                    'line1' => $address['line1'] ?? $address['address_line1'] ?? $address['street'] ?? null,
                    'line2' => $address['line2'] ?? $address['address_line2'] ?? null,
                    'city' => $address['city'] ?? $address['city_locality'] ?? null,
                    'state' => $address['state'] ?? $address['state_province'] ?? null,
                    'postal_code' => $address['postal_code'] ?? $address['zip'] ?? null,
                    'country' => $address['country'] ?? $address['country_code'] ?? 'US',
                ],
            ]);
        } elseif ($this->has('line1') || $this->has('address_line1') || $this->has('street')) {
            $this->merge([
                'address' => [
                    'line1' => $this->input('line1') ?? $this->input('address_line1') ?? $this->input('street'),
                    'line2' => $this->input('line2') ?? $this->input('address_line2'),
                    'city' => $this->input('city') ?? $this->input('city_locality'),
                    'state' => $this->input('state') ?? $this->input('state_province'),
                    'postal_code' => $this->input('postal_code') ?? $this->input('zip'),
                    'country' => $this->input('country') ?? $this->input('country_code') ?? 'US',
                ],
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // Optional address override; defaults to the order's stored
            // shipping_address. Validation never mutates stored data.
            'address' => ['nullable', 'array'],
            'address.line1' => ['required_with:address', 'string', 'max:500'],
            'address.line2' => ['nullable', 'string', 'max:500'],
            'address.city' => ['required_with:address', 'string', 'max:255'],
            'address.state' => ['nullable', 'string', 'max:255'],
            'address.postal_code' => ['required_with:address', 'string', 'max:20'],
            'address.country' => ['required_with:address', 'string', 'max:100'],
        ];
    }
}
