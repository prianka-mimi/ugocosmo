<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'company' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:500'],
            'salary_min' => ['nullable', 'numeric', 'min:0'],
            'salary_max' => ['nullable', 'numeric', 'min:0', 'gte:salary_min'],
            'job_type' => ['nullable', 'string', 'in:full-time,part-time,contract,internship,temporary'],
            'is_remote' => ['nullable', 'boolean'],
            'remote_position' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'end_date' => ['nullable', 'date', 'after:today', 'before_or_equal:'.now()->addDays(30)->format('Y-m-d')],
            'tags' => ['nullable', 'array', 'max:15'],
            'tags.*' => ['string', 'max:30'],
            'qualifying_questions' => ['nullable', 'array'],
            'qualifying_questions.*.question' => ['required', 'string'],
            'qualifying_questions.*.expected_answer' => ['required', 'string'],
            'qualifying_questions.*.required' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after' => 'The end date must be a date after today.',
            'end_date.before_or_equal' => 'The end date cannot exceed 30 days from today.',
            'tags.max' => 'You can only provide up to 15 tags.',
            'tags.*.max' => 'Each tag must not exceed 30 characters.',
            'qualifying_questions.*.question.required' => 'Each qualifying question must have a question text.',
            'qualifying_questions.*.expected_answer.required' => 'Each qualifying question must have an expected answer.',
        ];
    }
}
