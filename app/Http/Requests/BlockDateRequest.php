<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BlockDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'blocked_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
