<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use League\ISO3166\ISO3166;

/**
 * Validates the seller's AliExpress product search payload.
 *
 * `ship_to_country` is MANDATORY: AliExpress only returns products that are
 * actually deliverable to the requested destination, so the marketplace must
 * never fall back to a default market — a UK seller must not see US-only
 * listings. It is normalised to ISO 3166-1 alpha-2 upper case so callers can
 * rely on `$this->validated('ship_to_country')` being canonical.
 */
class SearchAliExpressProductsRequest extends FormRequest
{
    /** Page size is capped by the AliExpress search API, not by us. */
    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Canonical 2-letter country code, or null when the input is unusable.
     */
    public function shipToCountry(): ?string
    {
        $country = strtoupper(trim((string) $this->validated('ship_to_country')));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    /**
     * Trimmed keyword, or null when the seller is browsing without one.
     */
    public function keyword(): ?string
    {
        $keyword = trim((string) $this->validated('keyword', ''));

        return $keyword === '' ? null : $keyword;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 20);
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('ship_to_country')) {
            $raw = strtoupper(trim((string) $this->input('ship_to_country')));

            // Direct 2-letter uppercase match (e.g. US, UK, GB, CA, BD, AU)
            if (preg_match('/^[A-Z]{2}$/', $raw) === 1) {
                $this->merge(['ship_to_country' => $raw]);

                return;
            }

            // Common custom aliases/fallbacks
            $aliases = [
                'USA' => 'US',
                'UK' => 'GB',
                'ENGLAND' => 'GB',
                'AMERICA' => 'US',
                'UNITED STATES' => 'US',
                'UNITED STATES OF AMERICA' => 'US',
                'UNITED KINGDOM' => 'GB',
                'GREAT BRITAIN' => 'GB',
                'BANGLADESH' => 'BD',
                'CANADA' => 'CA',
                'AUSTRALIA' => 'AU',
                'GERMANY' => 'DE',
                'FRANCE' => 'FR',
                'SPAIN' => 'ES',
                'ITALY' => 'IT',
                'INDIA' => 'IN',
                'PAKISTAN' => 'PK',
            ];

            if (isset($aliases[$raw])) {
                $this->merge(['ship_to_country' => $aliases[$raw]]);

                return;
            }

            // Try resolving via League\ISO3166 library
            try {
                $iso = new ISO3166;
                if (strlen($raw) === 3) {
                    $record = $iso->alpha3($raw);
                    $this->merge(['ship_to_country' => $record['alpha2']]);

                    return;
                }

                $record = $iso->name($raw);
                $this->merge(['ship_to_country' => $record['alpha2']]);

                return;
            } catch (\Throwable $e) {
                // If unresolvable, leave raw so validation rule 'size:2' or 'alpha' flags it
            }

            $this->merge([
                'ship_to_country' => $raw,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // Mandatory — drives AliExpress deliverability filtering.
            'ship_to_country' => ['required', 'string', 'size:2', 'alpha'],

            // Optional search keywords; absent means "browse the feed".
            'keyword' => ['sometimes', 'nullable', 'string', 'min:2', 'max:200'],

            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],

            // Optional freight preview toggle — costs extra API calls.
            'include_freight' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ship_to_country.required' => 'A ship_to_country is required so only products deliverable to that destination are returned.',
            'ship_to_country.size' => 'ship_to_country must be a 2-letter ISO 3166-1 alpha-2 code (e.g. UK, US).',
            'ship_to_country.alpha' => 'ship_to_country must contain letters only (e.g. UK, US).',
            'keyword.min' => 'Keyword must be at least 2 characters.',
        ];
    }

    public function attributes(): array
    {
        return [
            'ship_to_country' => 'ship to country',
        ];
    }
}
