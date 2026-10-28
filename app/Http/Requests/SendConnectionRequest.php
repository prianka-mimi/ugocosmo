<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'connection_type' => ['required', 'string', 'in:professional,social'],
        ];
    }
}
