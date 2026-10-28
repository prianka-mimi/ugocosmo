<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CvResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cv' => ['required', 'file', 'mimes:pdf,doc,docx,txt,rtf', 'max:5120'],
        ];
    }
}
