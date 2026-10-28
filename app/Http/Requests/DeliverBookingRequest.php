<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliverBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_text' => ['nullable', 'string', 'max:5000'],
            'delivery_files' => ['nullable', 'array', 'max:10'],
            'delivery_files.*' => ['file', 'max:10240'], // 10MB max per file
        ];
    }
}
