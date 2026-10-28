<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => 'required|string|max:50|unique:roles,name,NULL,id,guard_name,web',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'role.unique' => 'A role with this name already exists.',
        ];
    }
}
