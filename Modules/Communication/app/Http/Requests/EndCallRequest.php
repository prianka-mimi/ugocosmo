<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Validation\Rule;

class EndCallRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(['completed', 'missed'])],
        ];
    }
}
