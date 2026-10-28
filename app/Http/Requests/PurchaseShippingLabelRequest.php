<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseShippingLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Purchase from a previously quoted rate...
            'rate_id' => ['nullable', 'string', 'max:64'],
            // ...or from an explicit carrier + service (mutually exclusive,
            // enforced in ShipmentService).
            'carrier_id' => ['nullable', 'string', 'max:64'],
            'service_code' => ['nullable', 'string', 'max:64'],
            'label_format' => ['nullable', 'string', 'in:pdf,png,zpl'],
            'label_layout' => ['nullable', 'string', 'in:4x6,letter'],
            'test_label' => ['nullable', 'boolean'],
        ];
    }
}
