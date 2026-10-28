<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Basic event fields
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:500'],
            'event_date' => ['nullable', 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after_or_equal:event_date'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['nullable', 'string', 'max:10'],
            'max_attendees' => ['nullable', 'integer', 'min:1'],
            'ticket_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[a-zA-Z0-9]+$/'],
            'is_active' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],

            // Media
            'image' => ['nullable', 'image', 'mimes:jpeg,png', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
            'video' => ['nullable', 'file', 'mimes:mp4,webm,mov', 'max:51200'],
            'remove_video' => ['nullable', 'boolean'],

            // Tags
            'tags' => ['nullable', 'array', 'max:15'],
            'tags.*' => ['string', 'max:30'],

            // Seat sections
            'sections' => ['nullable', 'array'],
            'sections.*.name' => ['required_with:sections', 'string', 'max:255'],
            'sections.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'sections.*.seats' => ['nullable', 'array'],
            'sections.*.seats.*' => ['string', 'max:50'],
            'sections.*.seat_ranges' => ['nullable', 'array'],
            'sections.*.seat_ranges.*' => ['string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'ticket_prefix.regex' => 'The ticket prefix may only contain letters and numbers.',
            'ticket_prefix.max' => 'The ticket prefix must not exceed 10 characters.',
            'image.max' => 'The image must not be larger than 5MB.',
            'video.max' => 'The video must not be larger than 50MB.',
            'tags.max' => 'You can only provide up to 15 tags.',
            'tags.*.max' => 'Each tag must not exceed 30 characters.',
            'sections.*.name.required_with' => 'Each section must have a name.',
        ];
    }
}
