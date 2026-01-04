<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdRate;
use Illuminate\Http\Request;

class AdminCpcRateController extends Controller
{
    /**
     * Get all CPC rates (default + country-specific)
     */
    public function index()
    {
        $rates = AdRate::all()->map(function ($rate) {
            return [
                'id' => $rate->id,
                'country' => $rate->country,
                'country_name' => $this->getCountryName($rate->country),
                'rates' => $rate->rates, // {level_1: x, level_2: x, level_3: x, level_4: x}
            ];
        });

        // Separate global and country-specific
        $global = $rates->where('country', 'GLOBAL')->first();
        $countries = $rates->where('country', '!=', 'GLOBAL')->values();

        return $this->successResponse([
            'default_rates' => $global ? $global['rates'] : [
                'level_1' => 0.05,
                'level_2' => 0.07,
                'level_3' => 0.10,
                'level_4' => 0.15,
            ],
            'country_rates' => $countries,
        ], 'CPC rates retrieved');
    }

    /**
     * Save all CPC rates (default + country-specific)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'default_rates' => 'required|array',
            'default_rates.level_1' => 'required|numeric|min:0',
            'default_rates.level_2' => 'required|numeric|min:0',
            'default_rates.level_3' => 'required|numeric|min:0',
            'default_rates.level_4' => 'required|numeric|min:0',
            'country_rates' => 'nullable|array',
            'country_rates.*.country' => 'required|string|max:10',
            'country_rates.*.rates' => 'required|array',
        ]);

        // Update or create GLOBAL rates
        AdRate::updateOrCreate(
            ['country' => 'GLOBAL'],
            ['rates' => $validated['default_rates']]
        );

        // Get existing country codes
        $existingCountries = AdRate::where('country', '!=', 'GLOBAL')
            ->pluck('country')
            ->toArray();

        // Update or create country-specific rates
        $submittedCountries = [];
        if (!empty($validated['country_rates'])) {
            foreach ($validated['country_rates'] as $countryRate) {
                $country = strtoupper($countryRate['country']);
                $submittedCountries[] = $country;

                AdRate::updateOrCreate(
                    ['country' => $country],
                    ['rates' => $countryRate['rates']]
                );
            }
        }

        // Remove countries that were deleted
        $countriesToDelete = array_diff($existingCountries, $submittedCountries);
        if (!empty($countriesToDelete)) {
            AdRate::whereIn('country', $countriesToDelete)->delete();
        }

        return $this->successResponse(null, 'CPC rates saved successfully');
    }

    /**
     * Add a country-specific rate
     */
    public function addCountry(Request $request)
    {
        $validated = $request->validate([
            'country' => 'required|string|max:10',
            'rates' => 'required|array',
            'rates.level_1' => 'required|numeric|min:0',
            'rates.level_2' => 'required|numeric|min:0',
            'rates.level_3' => 'required|numeric|min:0',
            'rates.level_4' => 'required|numeric|min:0',
        ]);

        $country = strtoupper($validated['country']);

        // Check if country already exists
        if (AdRate::where('country', $country)->exists()) {
            return $this->errorResponse('Country rate already exists', 422);
        }

        $rate = AdRate::create([
            'country' => $country,
            'rates' => $validated['rates'],
        ]);

        return $this->successResponse([
            'id' => $rate->id,
            'country' => $rate->country,
            'country_name' => $this->getCountryName($rate->country),
            'rates' => $rate->rates,
        ], 'Country rate added', 201);
    }

    /**
     * Remove a country-specific rate
     */
    public function removeCountry($country)
    {
        $country = strtoupper($country);

        if ($country === 'GLOBAL') {
            return $this->errorResponse('Cannot delete global rates', 422);
        }

        $rate = AdRate::where('country', $country)->first();

        if (!$rate) {
            return $this->errorResponse('Country rate not found', 404);
        }

        $rate->delete();

        return $this->successResponse([
            'deleted_country' => $country,
        ], 'Country rate deleted');
    }

    /**
     * Get country name from code
     */
    private function getCountryName($code)
    {
        $countries = [
            'GLOBAL' => 'Global (Default)',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'JP' => 'Japan',
            'ID' => 'Indonesia',
            'IN' => 'India',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'PH' => 'Philippines',
            'VN' => 'Vietnam',
            'TH' => 'Thailand',
            'MY' => 'Malaysia',
            'SG' => 'Singapore',
            'KR' => 'South Korea',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
        ];

        return $countries[$code] ?? $code;
    }
}
