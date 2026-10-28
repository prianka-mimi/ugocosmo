<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostRemixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'video' => ['required', 'file', 'mimes:mp4,mov,avi,wmv,flv,mkv', 'max:204800'],
            'content' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'video.required' => 'A remix video is required.',
            'video.mimes' => 'The video must be a file of type: mp4, mov, avi, wmv, flv, mkv.',
            'video.max' => 'The video file is too large. Maximum size is 200MB.',
            'video.uploaded' => 'The video failed to upload. Please check the file size and try again.',
        ];
    }
}
