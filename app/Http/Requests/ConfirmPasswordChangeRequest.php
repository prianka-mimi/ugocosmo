<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmPasswordChangeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'otp' => ['required', 'string', 'size:6'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
            'new_password_confirmation' => [
                'required',
                'string',
                'min:8',
                'same:new_password',
            ],
        ];
    }
}
