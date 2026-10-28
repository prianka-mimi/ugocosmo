<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Please provide a reason for reporting this post.',
            'reason.min' => 'The reason must be at least 10 characters.',
            'reason.max' => 'The reason must not exceed 1000 characters.',
        ];
    }
}
