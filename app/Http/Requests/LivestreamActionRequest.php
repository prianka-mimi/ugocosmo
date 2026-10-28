<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LivestreamActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:accepted,declined'],
        ];
    }
}
