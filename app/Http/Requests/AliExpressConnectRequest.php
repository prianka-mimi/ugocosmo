<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AliExpressConnectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'region' => ['sometimes', 'string', 'in:sg,global'],
        ];
    }
}
