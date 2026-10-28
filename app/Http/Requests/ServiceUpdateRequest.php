<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'price_type' => ['nullable', 'string', 'in:fixed,hourly,daily,weekly,monthly'],
            'service_type' => ['nullable', 'string', 'in:online,in-person,hybrid'],
            'delivery_location' => [
                'nullable',
                'string',
                'in:At my location (customer comes to me),At customer\'s address (I go to them),Both options available',
            ],
            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'country' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:150'],
            'service_area' => ['nullable', 'string', 'max:255'],
            'areas_of_coverage' => ['nullable', 'array'],
            'areas_of_coverage.*' => ['string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'estimated_delivery_time' => ['nullable', 'string', 'max:100'],
            'is_insured' => ['nullable', 'boolean'],
            'is_certified' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'video' => ['nullable', 'file', 'mimes:mp4,mov,webm', 'max:102400'],
            'remove_video' => ['nullable', 'boolean'],
            'location' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'packages' => ['nullable', 'array'],
            'packages.*.id' => ['nullable', 'integer', 'exists:service_packages,id'],
            'packages.*.title' => ['required_with:packages', 'string', 'max:100'],
            'packages.*.description' => ['nullable', 'string', 'max:2000'],
            'packages.*.price' => ['required_with:packages', 'numeric', 'min:0'],
            'tags' => ['nullable', 'array', 'max:15'],
            'tags.*' => ['string', 'max:30'],
        ];
    }
}
