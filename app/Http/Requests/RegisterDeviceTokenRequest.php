<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * ─── Fields ───────────────────────────────────────────────────────
     * - fcm_token:   The device registration token from Firebase SDK (required, string, max 500 chars).
     * - device_type: The platform type (required, one of: android, ios, web).
     */
    public function rules(): array
    {
        return [
            'fcm_token' => 'required|string|max:500',
            'device_type' => 'required|string|in:android,ios,web',
        ];
    }
}
