<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'institute_name' => ['nullable', 'string', 'max:255'],
            'degree' => ['nullable', 'string', 'max:255'],
            'field_of_study' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
