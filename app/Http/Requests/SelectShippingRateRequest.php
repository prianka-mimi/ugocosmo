<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/shipping/rates/select — persist the buyer's chosen rate.
 *
 * The rate_id must exist in a fresh backend re-quote (the frontend-submitted
 * shipping price is never trusted; only rate_id is accepted from the client).
 */
class SelectShippingRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Buyer, seller, or admin ownership is enforced in ShipmentService::selectRate
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'rate_id' => ['nullable', 'string', 'max:64', 'required_without:carrier_id'],
            'carrier_id' => ['nullable', 'string', 'max:64', 'required_without:rate_id'],
            'service_code' => ['nullable', 'string', 'max:64'],
            // Optional filters mirrored from GetShippingRatesRequest so the
            // re-quote returns the same rate the user saw.
            'rate_options' => ['nullable', 'array'],
            'rate_options.carrier_ids' => ['nullable', 'array'],
            'rate_options.carrier_ids.*' => ['string', 'max:64'],
            'rate_options.service_codes' => ['nullable', 'array'],
            'rate_options.service_codes.*' => ['string', 'max:64'],
            'rate_options.package_types' => ['nullable', 'array'],
            'rate_options.package_types.*' => ['string', 'max:64'],
        ];
    }
}
