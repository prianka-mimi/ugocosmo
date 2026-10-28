<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TermsAcceptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accept_terms' => ['required', 'boolean', 'accepted'],
            'accept_guidelines' => ['required', 'boolean', 'accepted'],
        ];
    }
}
