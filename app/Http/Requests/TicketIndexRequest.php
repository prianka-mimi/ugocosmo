<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TicketIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'in:confirmed,used,cancelled'],
        ];
    }
}
