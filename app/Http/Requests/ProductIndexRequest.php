<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductIndexRequest extends FormRequest
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
        }

        if ($this->has('condition') && $this->input('condition') !== null) {
            $this->merge([
                'condition' => strtolower(trim((string) $this->input('condition'))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'category' => ['nullable'],
            'condition' => ['nullable', 'string', 'in:new,used,refurbished'],
            'product_type' => ['nullable', 'string', 'in:physical,digital'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'search' => ['nullable', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:30'],
            'wholesale' => ['nullable', 'boolean'],
            'wholesale_only' => ['nullable', 'boolean'],
            'source' => ['nullable', 'string', 'in:local,aliexpress,shopify,etsy,csv'],
            'sort' => ['nullable', 'string', 'in:latest,oldest,newest,price_asc,price_desc,price_low_high,price_high_low,highest_rated,rating_desc,most_popular,popular'],
        ];
    }
}
