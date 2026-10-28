<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShopifyConnectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shop' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\-\.]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'shop.required' => 'Please provide your Shopify store name or domain.',
            'shop.regex' => 'The store name can only contain letters, numbers, hyphens, and dots.',
        ];
    }
}
