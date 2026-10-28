<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

class AddGroupMembersRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }
}
