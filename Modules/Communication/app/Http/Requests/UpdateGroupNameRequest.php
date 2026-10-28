<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

class UpdateGroupNameRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
