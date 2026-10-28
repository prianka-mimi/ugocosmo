<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Validation\Rule;

class MuteCallParticipantRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'integer', 'exists:users,id'],
            'media_kind' => ['nullable', 'string', Rule::in(['audio', 'video', 'both'])],
            'muted' => ['nullable', 'boolean'],
        ];
    }
}
