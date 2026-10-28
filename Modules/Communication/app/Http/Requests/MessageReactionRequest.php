<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

class MessageReactionRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'reaction' => ['nullable', 'string', 'max:32'],
        ];
    }
}
