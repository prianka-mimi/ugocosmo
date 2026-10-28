<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Uploaded file(s) ─────────────────────────────────────
            // Either a single `file` or a `files` array (multi-select).
            // Empty files (0 bytes) fail the mimes check and are rejected
            // again by structure validation; max:10240 = 10 MB each.
            'file' => ['sometimes', 'file', 'mimes:csv,txt', 'max:10240'],
            'files' => ['sometimes', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],

            // ── Import options ───────────────────────────────────────
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:product_categories,id'],
            'default_condition' => ['sometimes', 'string', 'in:new,used,refurbished'],
            'default_currency' => ['sometimes', 'string', 'size:3'],
            'download_images' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasFile('file') && ! $this->hasFile('files')) {
                $validator->errors()->add('files', 'Please select at least one CSV file to import.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Only .csv files are supported.',
            'file.max' => 'The uploaded file exceeds the 10 MB size limit.',
            'files.*.mimes' => 'Only .csv files are supported.',
            'files.*.max' => 'An uploaded file exceeds the 10 MB size limit.',
            'files.max' => 'A maximum of 20 files can be imported in one operation.',
        ];
    }
}
