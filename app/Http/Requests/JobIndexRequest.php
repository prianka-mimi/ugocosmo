<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'job_type' => ['nullable', 'string', 'in:full-time,part-time,contract,freelance,internship'],
            'is_remote' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'min_salary' => ['nullable', 'numeric', 'min:0'],
            'max_salary' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'string', 'in:latest,oldest,newest,price_asc,price_desc,price_low_high,price_high_low,salary_asc,salary_desc,salary_low_high,salary_high_low,highest_rated,rating_desc,most_popular,popular'],
            'tag' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', 'in:active,inactive,expired'],
            'remote' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'expired' => ['nullable', 'boolean'],
        ];
    }
}
