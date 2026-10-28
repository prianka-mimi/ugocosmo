<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_id' => ['required', 'integer', 'exists:profiles,id'],
            'content' => ['required', 'string', 'max:5000'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->addRules([
            'recipient_id' => [
                function ($attribute, $value, $fail) {
                    $user = auth()->guard('jwt')->user();
                    $profileId = (int) $user?->profile?->id;
                    if ((int) $value === $profileId) {
                        $fail('Cannot start a conversation with yourself.');
                    }
                },
            ],
        ]);
    }
}
