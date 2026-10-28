<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'type' => ['nullable', 'string', 'in:sales,purchases'],
            'role' => ['nullable', 'string', 'in:seller,buyer'],
        ];
    }
}
