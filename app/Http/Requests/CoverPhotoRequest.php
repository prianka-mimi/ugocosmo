<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CoverPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cover_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'remove_image' => ['nullable', 'in:0,1,true,false'],
        ];
    }
}
