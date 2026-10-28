<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AliExpressImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Support single string or comma-separated string for product_urls or product_ids
        if ($this->has('product_urls') && is_string($this->input('product_urls'))) {
            $this->merge([
                'product_urls' => array_filter(array_map('trim', explode(',', $this->input('product_urls')))),
            ]);
        }

        if ($this->has('product_ids') && is_string($this->input('product_ids'))) {
            $this->merge([
                'product_ids' => array_filter(array_map('trim', explode(',', $this->input('product_ids')))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // Accept either product_urls[] (full URLs) or product_ids[] (raw numeric IDs).
            // At least one of the two must be present.
            'product_urls' => ['sometimes', 'array', 'min:1', 'max:100'],
            'product_urls.*' => ['required_with:product_urls', 'string'],
            'product_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['required_with:product_ids', 'string'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:product_categories,id'],
            'profit_margin' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'download_images' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $hasUrls = ! empty($this->input('product_urls'));
            $hasIds = ! empty($this->input('product_ids'));
            if (! $hasUrls && ! $hasIds) {
                $v->errors()->add('product_urls', 'Provide either product_urls or product_ids.');
            }
        });
    }
}
