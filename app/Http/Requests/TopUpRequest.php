<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1', 'max:10000'],
            'payment_method' => ['nullable', 'string', 'in:stripe,bank_transfer'],
            'stripe_payment_intent_id' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Minimum top-up amount is 1.00',
            'amount.max' => 'Maximum top-up amount is 10,000.00',
            'stripe_payment_intent_id.required_if' => 'Stripe payment intent ID is required for Stripe payments',
        ];
    }
}
