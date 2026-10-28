<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Validation\Rule;

class SendMessageRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(['text', 'attachment', 'gift', 'system_call_event'])],
            'content' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:51200'],
            'metadata' => ['nullable', 'array'],
            'gift_id' => ['nullable', 'integer', 'required_if:type,gift', 'exists:gifts,id'],
        ];
    }
}
