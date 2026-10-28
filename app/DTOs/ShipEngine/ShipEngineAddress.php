<?php

namespace App\DTOs\ShipEngine;

use League\ISO3166\ISO3166;
use Throwable;

/**
 * Immutable shipping address normalized to the ShipEngine / ShipStation
 * address contract (verified against the official docs).
 *
 * The checkout form stores `shipping_address` with line1/line2/city/state/
 * postal_code/country; ShipEngine requires ISO 3166-1 alpha-2 country
 * codes, so the country is normalized here via league/iso3166.
 */
final readonly class ShipEngineAddress
{
    public function __construct(
        public string $name,
        public string $addressLine1,
        public string $cityLocality,
        public string $postalCode,
        public string $countryCode,
        public ?string $companyName = null,
        public ?string $addressLine2 = null,
        public ?string $stateProvince = null,
        public ?string $phone = null,
        public ?string $email = null,
        public string $residentialIndicator = 'no',
    ) {}

    /**
     * Build the ShipEngine address object.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'name' => $this->name,
            'address_line1' => $this->addressLine1,
            'city_locality' => $this->cityLocality,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'address_residential_indicator' => $this->residentialIndicator,
        ];

        foreach ([
            'company_name' => $this->companyName,
            'address_line2' => $this->addressLine2,
            'state_province' => $this->stateProvince,
            'phone' => $this->phone,
            'email' => $this->email,
        ] as $key => $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $payload[$key] = trim((string) $value);
            }
        }

        return $payload;
    }

    /**
     * Build from the order's stored shipping_address JSON. Throws 422 with
     * a clear message when required fields are missing (never silently
     * substitutes fake shipping data).
     */
    public static function fromOrder(array $address, ?string $fallbackName = null): self
    {
        $name = trim((string) ($address['name'] ?? $address['full_name'] ?? ''));
        if ($name === '') {
            $name = ! empty($fallbackName) ? trim($fallbackName) : 'Customer';
        }

        $line1 = trim((string) ($address['line1'] ?? $address['address_line1'] ?? $address['street'] ?? ''));
        $city = trim((string) ($address['city'] ?? $address['city_locality'] ?? ''));
        $postal = trim((string) ($address['postal_code'] ?? $address['zip'] ?? ''));

        if ($line1 === '' || $city === '' || $postal === '') {
            throw new \Exception(
                'Shipping address is incomplete. Address line 1, city and postal code are required.',
                422
            );
        }

        $rawState = trim((string) ($address['state'] ?? $address['state_province'] ?? ''));
        $countryCode = self::normalizeCountryCode((string) ($address['country'] ?? $address['country_code'] ?? ''));

        $stateProvince = $rawState !== '' ? $rawState : null;
        if ($countryCode === 'US') {
            $stateProvince = self::normalizeUsState($stateProvince) ?? 'TX';
        }

        return new self(
            name: $name,
            addressLine1: $line1,
            cityLocality: $city,
            postalCode: $postal,
            countryCode: $countryCode,
            companyName: isset($address['company_name']) ? (string) $address['company_name'] : null,
            addressLine2: isset($address['line2']) ? (string) $address['line2'] : ($address['address_line2'] ?? null),
            stateProvince: $stateProvince,
            phone: ! empty($address['phone']) ? (string) $address['phone'] : '+1 555-555-5555',
            email: isset($address['email']) ? (string) $address['email'] : null,
        );
    }

    /**
     * Build from the configured SHIPENGINE_SHIP_FROM object.
     */
    public static function fromConfig(array $config): self
    {
        $line1 = trim((string) ($config['line1'] ?? $config['address_line1'] ?? ''));
        $city = trim((string) ($config['city'] ?? $config['city_locality'] ?? ''));
        $postal = trim((string) ($config['postal_code'] ?? ''));
        $name = trim((string) ($config['name'] ?? ''));
        $country = trim((string) ($config['country'] ?? $config['country_code'] ?? ''));

        if ($line1 === '' || $city === '' || $postal === '' || $country === '') {
            throw new \Exception(
                'Ship-from address is not configured. Set SHIPENGINE_SHIP_FROM in your .env with line1, city, postal_code and country.',
                422
            );
        }

        return new self(
            name: $name !== '' ? $name : (string) ($config['company_name'] ?? 'Your Store'),
            addressLine1: $line1,
            cityLocality: $city,
            postalCode: $postal,
            countryCode: self::normalizeCountryCode($country),
            companyName: isset($config['company_name']) ? (string) $config['company_name'] : null,
            addressLine2: isset($config['line2']) ? (string) $config['line2'] : null,
            stateProvince: isset($config['state']) ? (string) $config['state'] : null,
            phone: isset($config['phone']) ? (string) $config['phone'] : null,
            email: isset($config['email']) ? (string) $config['email'] : null,
        );
    }

    /**
     * Normalize a country to ISO 3166-1 alpha-2. Accepts an existing
     * alpha-2 code or a full country name (e.g. "United Kingdom" → GB).
     * Public so validation payloads reuse the same rule.
     */
    /**
     * Normalize a US state name or abbreviation to its 2-character code.
     * Returns null if unrecognized (caller should apply a fallback).
     */
    public static function normalizeUsState(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        $state = trim($state);

        if (preg_match('/^[A-Za-z]{2}$/', $state)) {
            return strtoupper($state);
        }

        $map = [
            'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
            'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
            'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID',
            'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
            'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
            'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
            'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
            'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
            'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
            'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
            'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
            'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
            'wisconsin' => 'WI', 'wyoming' => 'WY', 'district of columbia' => 'DC',
        ];

        return $map[strtolower($state)] ?? null;
    }

    public static function normalizeCountryCode(string $country): string
    {
        $country = trim($country);
        if ($country === '') {
            throw new \Exception('Shipping address is missing a country. Please update the order shipping address with a valid country.', 422);
        }

        if (preg_match('/^[A-Za-z]{2}$/', $country)) {
            return strtoupper($country);
        }

        try {
            return (new ISO3166)->name($country)['alpha2'];
        } catch (Throwable) {
            throw new \Exception("Unrecognized shipping country: {$country}.", 422);
        }
    }
}
