<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LivestreamAddItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'job_id' => ['nullable', 'integer', 'exists:job_listings,id'],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
