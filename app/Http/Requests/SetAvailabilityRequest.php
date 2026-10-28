<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $normalized = [];

        foreach ($input as $key => $value) {
            $cleanKey = trim($key);
            $cleanKey = rtrim($cleanKey, ' _');
            $normalized[$cleanKey] = $value;
        }

        $this->replace($normalized);
    }

    public function rules(): array
    {
        $rules = [
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'is_available' => ['nullable', 'boolean'],
            'slots' => ['present', 'array'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i', 'after:slots.*.start_time'],
            'slots.*.is_available' => ['nullable', 'boolean'],
        ];

        // If root-level day_of_week is not present, each slot must have day_of_week.
        if (! $this->has('day_of_week')) {
            $rules['slots.*.day_of_week'] = ['required', 'integer', 'between:0,6'];
        } else {
            $rules['slots.*.day_of_week'] = ['nullable', 'integer', 'between:0,6'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'slots.*.end_time.after' => 'End time must be after start time for each slot.',
        ];
    }
}
