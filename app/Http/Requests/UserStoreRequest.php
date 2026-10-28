<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['nullable', 'string', Rule::in(['user', 'supervisor'])],
            'mobile_number' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string'],
            'address_line_2' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
