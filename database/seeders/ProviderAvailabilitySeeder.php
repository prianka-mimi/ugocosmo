<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\ProviderAvailability;
use Illuminate\Database\Seeder;

class ProviderAvailabilitySeeder extends Seeder
{
    public function run(): void
    {
        $providers = Profile::whereHas('user', fn ($q) => $q->whereIn('email', [
            'alice@example.com', 'bob@example.com', 'eva@example.com',
        ]))->get();

        // Mon–Fri (1–5), 09:00–17:00
        foreach ($providers as $provider) {
            foreach (range(1, 5) as $day) {
                ProviderAvailability::firstOrCreate(['provider_id' => $provider->id, 'day_of_week' => $day], [
                    'provider_id' => $provider->id,
                    'day_of_week' => $day,
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'is_available' => true,
                ]);
            }
        }
    }
}
