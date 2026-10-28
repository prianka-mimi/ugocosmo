<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\JobListing;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SavedItemSeeder extends Seeder
{
    public function run(): void
    {
        $p = $this->profiles();

        $david = $p['david'] ?? null;
        $jack = $p['jack'] ?? null;
        $grace = $p['grace'] ?? null;
        $henry = $p['henry'] ?? null;

        if (! $david || ! $jack || ! $grace || ! $henry) {
            return;
        }

        // David saves products
        foreach (Product::take(4)->get() as $product) {
            DB::table('product_saves')->insertOrIgnore(['user_id' => $david->id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Jack saves services
        foreach (Service::take(4)->get() as $service) {
            DB::table('service_saves')->insertOrIgnore(['user_id' => $jack->id, 'service_id' => $service->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Grace saves jobs
        foreach (JobListing::take(4)->get() as $job) {
            DB::table('job_saves')->insertOrIgnore(['user_id' => $grace->id, 'job_id' => $job->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Henry saves events
        foreach (Event::take(3)->get() as $event) {
            DB::table('event_saves')->insertOrIgnore(['user_id' => $henry->id, 'event_id' => $event->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Cross-saves
        foreach (Product::skip(2)->take(2)->get() as $product) {
            DB::table('product_saves')->insertOrIgnore(['user_id' => $grace->id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (Service::skip(2)->take(2)->get() as $service) {
            DB::table('service_saves')->insertOrIgnore(['user_id' => $david->id, 'service_id' => $service->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function profiles(): array
    {
        $map = [];
        foreach (['alice', 'bob', 'carol', 'david', 'eva', 'frank', 'grace', 'henry', 'isla', 'jack'] as $u) {
            $pr = Profile::whereHas('user', fn ($q) => $q->where('username', $u))->first();
            if ($pr) {
                $map[$u] = $pr;
            }
        }

        return $map;
    }
}
