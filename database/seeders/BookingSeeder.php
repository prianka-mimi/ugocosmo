<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Profile;
use App\Models\Service;
use Illuminate\Database\Seeder;

class BookingSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = $this->profiles();

        // [customer, provider] pairs — customer != provider
        $pairs = [
            ['david', 'alice'],
            ['david', 'bob'],
            ['jack',  'carol'],
            ['henry', 'grace'],
            ['isla',  'eva'],
            ['frank', 'alice'],
            ['bob',   'carol'],
            ['alice', 'grace'],
        ];

        $statuses = ['pending', 'confirmed', 'completed', 'confirmed', 'pending', 'completed', 'confirmed', 'pending'];

        foreach ($pairs as $i => [$customerKey, $providerKey]) {
            $customer = $profiles[$customerKey] ?? null;
            $provider = $profiles[$providerKey] ?? null;
            if (! $customer || ! $provider) {
                continue;
            }

            $service = Service::where('provider_id', $provider->id)->first();
            if (! $service) {
                continue;
            }

            Booking::firstOrCreate(['customer_id' => $customer->id, 'service_id' => $service->id], [
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'provider_id' => $provider->id,
                'booking_date' => now()->addDays(7 + $i)->toDateString(),
                'booking_time' => '10:00:00',
                'status' => $statuses[$i] ?? 'pending',
                'notes' => 'Looking forward to the session.',
                'subtotal' => $service->price,
                'buyer_fee' => round($service->price * 0.03, 2),
                'seller_fee' => round($service->price * 0.05, 2),
                'platform_fee_total' => round($service->price * 0.08, 2),
                'total' => round($service->price * 1.03, 2),
            ]);
        }
    }

    private function profiles(): array
    {
        $map = [];
        foreach (['alice', 'bob', 'carol', 'david', 'eva', 'frank', 'grace', 'henry', 'isla', 'jack'] as $u) {
            $p = Profile::whereHas('user', fn ($q) => $q->where('username', $u))->first();
            if ($p) {
                $map[$u] = $p;
            }
        }

        return $map;
    }
}
