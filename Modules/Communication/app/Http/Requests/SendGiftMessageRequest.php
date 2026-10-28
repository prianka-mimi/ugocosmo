<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

class SendGiftMessageRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'gift_id' => ['required', 'integer', 'exists:gifts,id'],
            'content' => ['nullable', 'string'],
        ];
    }
}
