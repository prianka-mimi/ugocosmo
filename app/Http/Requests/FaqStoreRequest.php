<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FaqStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
