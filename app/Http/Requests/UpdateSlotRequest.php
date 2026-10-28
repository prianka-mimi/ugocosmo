<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['sometimes', 'required', 'integer', 'between:0,6'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i', 'after:start_time'],
            'is_available' => ['nullable', 'boolean'],
        ];
    }
}
