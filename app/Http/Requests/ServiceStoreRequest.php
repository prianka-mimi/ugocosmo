<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:10'],
            'price_type' => ['required', 'string', 'in:fixed,hourly,daily,weekly,monthly'],
            'service_type' => ['required', 'string', 'in:online,in-person,hybrid'],
            'delivery_location' => [
                'required_unless:service_type,online',
                'nullable',
                'string',
                'in:At my location (customer comes to me),At customer\'s address (I go to them),Both options available',
            ],
            'address' => [
                'required_if:delivery_location,At my location (customer comes to me),Both options available',
                'nullable',
                'string',
                'max:1000',
            ],
            'country' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:150'],
            'service_area' => ['nullable', 'string', 'max:255'],
            'areas_of_coverage' => ['nullable', 'array'],
            'areas_of_coverage.*' => ['string', 'max:150'],
            'category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'estimated_delivery_time' => ['nullable', 'string', 'max:100'],
            'is_insured' => ['nullable', 'boolean'],
            'is_certified' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'video' => ['nullable', 'file', 'mimes:mp4,mov,webm', 'max:102400'],
            'location' => ['nullable', 'string', 'max:500'],
            'packages' => ['nullable', 'array'],
            'packages.*.title' => ['required_with:packages', 'string', 'max:100'],
            'packages.*.description' => ['nullable', 'string', 'max:2000'],
            'packages.*.price' => ['required_with:packages', 'numeric', 'min:0'],
            'tags' => ['nullable', 'array', 'max:15'],
            'tags.*' => ['string', 'max:30'],
        ];
    }
}
