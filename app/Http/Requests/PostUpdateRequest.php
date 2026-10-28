<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'video' => ['nullable', 'file', 'mimes:mp4,mov,avi,wmv,flv,mkv'],
            'media' => ['nullable', 'array'],
            'media.*' => ['file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'attached_item_type' => ['nullable', 'string', 'in:product,service,event,job_listing'],
            'attached_item_id' => ['nullable', 'integer', 'required_with:attached_item_type'],
            'gif_url' => ['nullable', 'string', 'url', 'max:1000'],
            'emoji' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'video.max' => 'The video file is too large. Maximum size is 200MB.',
            'video.uploaded' => 'The video failed to upload. Please check the file size and try again.',
        ];
    }
}
