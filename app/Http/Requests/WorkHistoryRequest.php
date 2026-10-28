<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:255'],
            'position_title' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'job_description' => ['nullable', 'string', 'max:1000'],
            'is_currently_working' => ['nullable', 'boolean'],
        ];
    }
}
