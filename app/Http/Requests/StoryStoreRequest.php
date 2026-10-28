<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'media' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,webp,mp4,mov,avi'],
            'media_type' => ['required', 'string', 'in:image,video'],
            'caption' => ['nullable', 'string', 'max:500'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }
}
