<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AliExpressProductPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => ['sometimes', 'string', 'max:255'],
            'product_status_type' => ['sometimes', 'string', 'max:32'],
            'product_id' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
