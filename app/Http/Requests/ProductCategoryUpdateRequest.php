<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductCategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('product_type') && $this->input('product_type') !== null) {
            $str = strtolower(trim((string) $this->input('product_type')));
            if (str_contains($str, 'digital') || str_contains($str, 'virtual')) {
                $this->merge(['product_type' => 'digital']);
            } else {
                $this->merge(['product_type' => 'physical']);
            }
        }
    }

    public function rules(): array
    {
        $categoryId = $this->route('productCategory')?->id;

        return [
            'name' => ['nullable', 'string', 'max:255', 'unique:product_categories,name,'.$categoryId],
            'product_type' => ['nullable', 'string', 'in:physical,digital'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
