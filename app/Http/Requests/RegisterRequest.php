<?php

namespace App\Http\Requests;

// use Illuminate\Contracts\Validation\ValidationRule;
use App\Rules\Iso3166CountryCode;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation (e.g. normalize country name to ISO alpha-2 code).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('country') && is_string($this->country) && trim($this->country) !== '') {
            try {
                $code = \App\DTOs\ShipEngine\ShipEngineAddress::normalizeCountryCode($this->country);
                $this->merge(['country' => $code]);
            } catch (\Throwable) {
                // Keep original value if not normalized
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
    //  * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', 'in:individual,business,seller'],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username', 'regex:/^[a-zA-Z0-9_]+$/'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'address_line_1' => ['nullable', 'string', 'max:500'],
            'address_line_2' => ['nullable', 'string', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
