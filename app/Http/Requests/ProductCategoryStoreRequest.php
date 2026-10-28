<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductCategoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:product_categories,name'],
            'product_type' => ['nullable', 'string', 'in:physical,digital'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
