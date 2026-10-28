<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PageStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'slug' => ['required', 'string', 'max:255', 'unique:pages,slug'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
