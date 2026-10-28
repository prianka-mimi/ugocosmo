<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

class ListConversationsRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'query' => ['nullable', 'string', 'max:255'],
        ];
    }
}
