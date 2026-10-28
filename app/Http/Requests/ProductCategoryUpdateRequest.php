<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductCategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
