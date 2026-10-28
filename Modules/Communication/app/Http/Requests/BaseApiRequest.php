<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Communication\Traits\ApiResponseTrait;

abstract class BaseApiRequest extends FormRequest
{
    use ApiResponseTrait;

    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        $message = $validator->errors()->first() ?: 'The given data is invalid.';

        throw new HttpResponseException(
            $this->sendError($message, 422, $validator->errors()->toArray())
        );
    }
}
