<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'evidence_urls' => ['nullable', 'array'],
            'evidence_urls.*' => ['url'],
        ];
    }
}
