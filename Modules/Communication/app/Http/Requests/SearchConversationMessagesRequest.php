<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

class SearchConversationMessagesRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:255', 'required_without:search'],
            'search' => ['nullable', 'string', 'max:255', 'required_without:query'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
