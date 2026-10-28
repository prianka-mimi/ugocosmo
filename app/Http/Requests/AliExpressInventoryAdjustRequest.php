<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AliExpressInventoryAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer'], // signed: +N in, -N out
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
