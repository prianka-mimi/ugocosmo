<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;

class AliExpressOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:'.implode(',', OrderStatus::values())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
