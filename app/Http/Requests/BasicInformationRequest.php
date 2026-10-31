<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BasicInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'dob' => ['nullable', 'date', 'before_or_equal:today'],
            'marital_status' => ['nullable', 'string', 'max:50'],
        ];
    }
}
