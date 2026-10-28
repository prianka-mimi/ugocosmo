<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AliExpressUpdateImportedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'selling_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'profit_margin' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:product_categories,id'],
            'featured_image' => ['sometimes', 'nullable', 'image', 'max:5120'],
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['string', 'max:2048'],
            'product_variants' => ['sometimes', 'array'],
        ];
    }
}
