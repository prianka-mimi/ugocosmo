<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerificationSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['nullable', 'string', 'max:255'],
            'success_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
            'photo_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'proof_of_address' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'selfie' => ['required_without:selfies', 'nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'selfies' => ['required_without:selfie', 'nullable', 'array', 'min:1'],
            'selfies.*' => ['file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'business_insurance' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'certification' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'branding_license' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'incorporation_certificate' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
