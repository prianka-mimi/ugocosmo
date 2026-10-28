<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Validation\Rule;

class InitiateCallRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'integer', 'exists:communication_conversations,id'],
            'call_type' => ['required', 'string', Rule::in(['audio', 'video'])],
        ];
    }
}
