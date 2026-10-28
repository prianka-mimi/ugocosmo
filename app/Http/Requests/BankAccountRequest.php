<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
            'sort_code' => ['nullable', 'string', 'max:20'],
            'iban' => ['nullable', 'string', 'max:34'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'bank_statement' => [$this->isMethod('post') ? 'required' : 'nullable', 'file', 'mimes:pdf,jpeg,png,jpg', 'max:10240'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
