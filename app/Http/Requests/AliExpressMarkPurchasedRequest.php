<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AliExpressMarkPurchasedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'supplier_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'supplier_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'purchase_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
