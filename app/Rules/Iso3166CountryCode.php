<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use League\ISO3166\ISO3166;

class Iso3166CountryCode implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) !== 2) {
            $fail('The :attribute must be a valid ISO 3166-1 alpha-2 country code.');

            return;
        }

        $iso3166 = new ISO3166;

        try {
            $iso3166->alpha2(mb_strtoupper($value));
        } catch (\Throwable $e) {
            $fail('The :attribute must be a valid ISO 3166-1 alpha-2 country code.');
        }
    }
}
