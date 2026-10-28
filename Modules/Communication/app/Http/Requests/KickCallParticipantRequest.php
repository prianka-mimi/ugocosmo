<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

class KickCallParticipantRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
