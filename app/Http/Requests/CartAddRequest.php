<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CartAddRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
