<?php

namespace App\Http\Requests;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GetShippingRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            // Optional ShipEngine rate filters (verified API contract).
            'rate_options' => ['nullable', 'array'],
            'rate_options.carrier_ids' => ['nullable', 'array'],
            'rate_options.carrier_ids.*' => ['string', 'max:64'],
            'rate_options.service_codes' => ['nullable', 'array'],
            'rate_options.service_codes.*' => ['string', 'max:64'],
            'rate_options.package_types' => ['nullable', 'array'],
            'rate_options.package_types.*' => ['string', 'max:64'],
            // Address validation level used for the rate request:
            // no_validation (default) | validate_only | validate_and_clean
            'validate_address' => ['nullable', 'string', 'in:no_validation,validate_only,validate_and_clean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error(
                $validator->errors()->first(),
                422,
                $validator->errors()->toArray()
            )
        );
    }
}
