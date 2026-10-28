<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'in:user,supervisor,admin'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'Role must be one of: user, supervisor, admin',
        ];
    }
}
