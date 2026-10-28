<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the staged review import payload from the AliExpress review screen.
 *
 * Supports both:
 * 1. Committing by import_id (fetches staged products saved during initial import)
 * 2. Directly providing the reviewed products[] array
 */
class AliExpressStagedImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'import_id' => ['sometimes', 'nullable', 'integer', 'exists:aliexpress_imports,id'],
            'products' => ['required_without:import_id', 'array', 'min:1', 'max:100'],
            'products.*.aliexpress_product_id' => ['required_with:products', 'string', 'max:64'],
            'products.*.title' => ['required_with:products', 'string', 'max:255'],
            'products.*.supplier_cost' => ['nullable', 'numeric', 'min:0'],
            'products.*.profit_markup' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'products.*.price' => ['required_with:products', 'numeric', 'min:0'],
            'products.*.stock' => ['nullable', 'integer', 'min:0'],
            'products.*.stock_quantity' => ['nullable', 'integer', 'min:0'],
            'products.*.category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'products.*.image_url' => ['nullable', 'string', 'max:1000'],
            'products.*.shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'products.*.description' => ['nullable', 'string', 'max:5000'],
            'products.*.currency' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'products.required_without' => 'Provide either import_id or a products array for staged import.',
            'products.*.aliexpress_product_id.required' => 'Each product must include its AliExpress product ID.',
            'products.*.title.required' => 'Each product must have a title.',
            'products.*.price.required' => 'Each product must have a selling price.',
        ];
    }
}
