<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HeaderInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:100'],
            'skills' => ['nullable', 'array', 'max:20'],
            'skills.*' => ['string', 'max:50'],
            'interests' => ['nullable', 'array', 'max:20'],
            'interests.*' => ['string', 'max:50'],
        ];
    }
}
