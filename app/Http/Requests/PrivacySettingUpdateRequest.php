<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrivacySettingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'age_visibility' => ['nullable', 'string', 'in:public,verified,private'],
            'employment_history_visibility' => ['nullable', 'string', 'in:public,verified,private'],
            'qualifications_visibility' => ['nullable', 'string', 'in:public,verified,private'],
            'profile_visibility' => ['nullable', 'string', 'in:public,verified,private'],
            'show_online_status' => ['nullable', 'boolean'],
            'message_permission' => ['nullable', 'string', 'in:everyone,connections,verified'],
        ];
    }
}
