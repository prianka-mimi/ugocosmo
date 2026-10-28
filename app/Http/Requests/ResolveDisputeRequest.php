<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', 'in:refund_buyer,release_seller'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
            'refund_amount' => ['required_if:resolution,refund_buyer', 'nullable', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution.in' => 'Resolution must be either refund_buyer or release_seller',
            'refund_amount.required_if' => 'Refund amount is required when refunding the buyer',
        ];
    }
}
