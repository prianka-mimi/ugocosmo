<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LivestreamStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ];
    }
}
