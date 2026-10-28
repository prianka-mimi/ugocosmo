<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

class UpdateGroupAvatarRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'avatar' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
