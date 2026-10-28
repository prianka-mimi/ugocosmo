<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invitee_id' => ['required', 'integer', 'exists:profiles,id'],
        ];
    }
}
