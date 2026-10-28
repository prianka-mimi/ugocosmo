<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobApplicationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $job = $this->route('job');
        $hasQuestions = $job && $job->qualifyingQuestions()->exists();

        return [
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'cv_file' => ['nullable', 'file', 'mimes:pdf,doc,docx,txt,rtf', 'max:5120'],
            'answers' => [$hasQuestions ? 'required' : 'nullable', 'array'],
            'answers.*.question_id' => ['required_with:answers', 'integer', 'exists:job_qualifying_questions,id'],
            'answers.*.answer' => ['required_with:answers', 'string', 'in:yes,no,Yes,No,YES,NO'],
        ];
    }

    public function messages(): array
    {
        return [
            'answers.required' => 'You must answer the qualifying questions to apply.',
            'answers.*.answer.in' => 'The answer must be either yes or no.',
            'answers.*.question_id.exists' => 'The selected qualifying question is invalid.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $job = $this->route('job');
            if (! $job) {
                return;
            }

            $questionIds = $job->qualifyingQuestions()->pluck('id')->toArray();
            if (empty($questionIds)) {
                return;
            }

            $submittedAnswers = $this->input('answers', []);
            if (! is_array($submittedAnswers)) {
                return;
            }

            $submittedQuestionIds = collect($submittedAnswers)
                ->pluck('question_id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            foreach ($questionIds as $qId) {
                if (! in_array($qId, $submittedQuestionIds, true)) {
                    $validator->errors()->add('answers', 'You must answer all qualifying questions.');
                    break;
                }
            }

            foreach ($submittedAnswers as $index => $subAnswer) {
                if (isset($subAnswer['question_id']) && in_array((int) $subAnswer['question_id'], $questionIds, true)) {
                    if (! isset($subAnswer['answer']) || trim((string) $subAnswer['answer']) === '') {
                        $validator->errors()->add("answers.{$index}.answer", 'The answer for this qualifying question is required.');
                    }
                }
            }
        });
    }
}
