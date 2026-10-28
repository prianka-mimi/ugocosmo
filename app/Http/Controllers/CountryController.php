<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use League\ISO3166\ISO3166;

class CountryController extends Controller
{
    /**
     * Get all countries for the registration dropdown.
     * Sourced from the official ISO 3166-1 standard via league/iso3166.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $iso3166 = new ISO3166;
            $countries = $iso3166->all();

            // Sort by name, then assign sequential numeric IDs
            usort($countries, fn ($a, $b) => strcmp($a['name'], $b['name']));

            $countries = array_map(fn ($index, $country) => [
                'id' => $index + 1,
                'code' => $country['alpha2'],
                'name' => $country['name'],
            ], array_keys($countries), $countries);

            // Apply search filter if provided
            if ($search = $request->query('search')) {
                $search = mb_strtolower($search);
                $countries = array_filter($countries, function ($country) use ($search) {
                    return str_contains(mb_strtolower($country['name']), $search)
                        || str_contains(mb_strtolower($country['code']), $search);
                });
                $countries = array_values($countries); // re-index
            }

            $this->data = $countries;
            $this->status_message = 'Countries retrieved successfully';
            $this->status_code = 200;
        } catch (\Throwable $e) {
            $this->status_message = 'Failed to retrieve countries';
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
