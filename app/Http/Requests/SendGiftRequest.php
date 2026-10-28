<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_id' => ['required', 'integer', 'exists:profiles,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'livestream_id' => ['nullable', 'integer', 'exists:livestreams,id'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_id.exists' => 'The recipient profile does not exist.',
            'amount.min' => 'Minimum gift amount is 0.01',
        ];
    }
}
