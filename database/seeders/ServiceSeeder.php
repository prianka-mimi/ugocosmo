<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = $this->profiles();
        $cat = ServiceCategory::first();

        $services = [
            ['provider' => 'alice', 'title' => 'UI/UX Design Consultation',   'description' => 'One-on-one design consultation to review your product.',       'price' => 80.00,  'price_type' => 'fixed',  'service_type' => 'online',    'location' => 'Remote'],
            ['provider' => 'alice', 'title' => 'Full Website Redesign',        'description' => 'End-to-end website redesign including wireframes & handoff.',   'price' => 800.00, 'price_type' => 'fixed',  'service_type' => 'online',    'location' => 'Remote'],
            ['provider' => 'bob',   'title' => 'Laravel API Development',      'description' => 'Custom REST API built with Laravel, fully documented.',         'price' => 120.00, 'price_type' => 'hourly', 'service_type' => 'online',    'location' => 'Remote'],
            ['provider' => 'bob',   'title' => 'Code Review & Security Audit', 'description' => 'Thorough code review with written report & recommendations.',   'price' => 250.00, 'price_type' => 'fixed',  'service_type' => 'online',    'location' => 'Remote'],
            ['provider' => 'carol', 'title' => 'Corporate Event Planning',     'description' => 'Full-service corporate event planning from concept to delivery.', 'price' => 1500.00, 'price_type' => 'fixed',  'service_type' => 'in_person', 'location' => 'Toronto, CA'],
            ['provider' => 'carol', 'title' => 'Wedding Planning Consultation', 'description' => 'Initial wedding planning consultation (2 hours).',              'price' => 200.00, 'price_type' => 'fixed',  'service_type' => 'in_person', 'location' => 'Toronto, CA'],
            ['provider' => 'eva',   'title' => 'Portrait Photography Session', 'description' => '2-hour professional portrait session, 20 edited photos.',       'price' => 180.00, 'price_type' => 'fixed',  'service_type' => 'in_person', 'location' => 'Berlin, DE'],
            ['provider' => 'eva',   'title' => 'Full-Day Event Photography',   'description' => 'Up to 8 hours of event coverage, 100+ edited photos.',          'price' => 700.00, 'price_type' => 'fixed',  'service_type' => 'in_person', 'location' => 'Berlin, DE'],
            ['provider' => 'frank', 'title' => 'Business Strategy Session',    'description' => '90-minute strategy session for startups & SMEs.',               'price' => 300.00, 'price_type' => 'fixed',  'service_type' => 'online',    'location' => 'Remote'],
            ['provider' => 'grace', 'title' => 'Personal Training Session',    'description' => '1-hour personal training session tailored to your goals.',      'price' => 75.00,  'price_type' => 'fixed',  'service_type' => 'in_person', 'location' => 'Los Angeles, US'],
            ['provider' => 'henry', 'title' => 'Music Production Session',     'description' => '3-hour studio session to produce your track.',                  'price' => 200.00, 'price_type' => 'fixed',  'service_type' => 'in_person', 'location' => 'Nashville, US'],
            ['provider' => 'isla',  'title' => 'Interior Design Consultation', 'description' => '1-hour online interior design consultation with mood board.',   'price' => 120.00, 'price_type' => 'fixed',  'service_type' => 'online',    'location' => 'Remote'],
        ];

        foreach ($services as $data) {
            $profile = $profiles[$data['provider']] ?? null;
            if (! $profile) {
                continue;
            }
            unset($data['provider']);

            $service = Service::firstOrCreate(['title' => $data['title'], 'provider_id' => $profile->id], array_merge($data, [
                'provider_id' => $profile->id,
                'category_id' => $cat?->id,
                'is_active' => true,
            ]));

            // Add packages to each service
            ServicePackage::firstOrCreate(['service_id' => $service->id, 'title' => 'Basic'], [
                'service_id' => $service->id,
                'title' => 'Basic',
                'description' => 'Essential package with core deliverables.',
                'price' => $service->price,
            ]);
            ServicePackage::firstOrCreate(['service_id' => $service->id, 'title' => 'Premium'], [
                'service_id' => $service->id,
                'title' => 'Premium',
                'description' => 'Full package with priority support & extras.',
                'price' => round($service->price * 1.8, 2),
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
