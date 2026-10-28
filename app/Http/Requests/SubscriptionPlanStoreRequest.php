<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionPlanStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:subscription_plans,slug'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'features' => ['nullable', 'string'],
            'is_popular' => ['boolean'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
