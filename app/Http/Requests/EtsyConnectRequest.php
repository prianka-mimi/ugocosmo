<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EtsyConnectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'app_redirect' => ['sometimes', 'nullable', 'string', 'url'],
        ];
    }
}
