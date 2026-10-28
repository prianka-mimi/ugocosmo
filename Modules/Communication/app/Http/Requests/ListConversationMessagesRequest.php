<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

class ListConversationMessagesRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'lang' => ['nullable', 'string', 'max:100'],
        ];
    }
}
