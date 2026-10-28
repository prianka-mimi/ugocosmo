<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
        $rules = [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'otp' => ['required', 'string', 'size:6'],
        ];

        if (str_contains($this->path(), 'reset-password')) {
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        return $rules;
    }
}
