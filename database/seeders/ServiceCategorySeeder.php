<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Software Development',
            'Writing & Editing',
            'Copywriting',
            'Marketing Services',
            'Video Creation & Voice Overs',
            'Graphic Design',
            'Virtual Assistance',
            'Technical Support',
            'Game Design',
            'Electrical & Plumbing Services',
            'Vehicle Maintenance & Repairs',
            'Hairdressing & Beauty Services',
            'Cleaning Services',
            'Moving & Van Hire',
            'Landscaping & Grounds Maintenance',
            'Security Services',
            'Healthcare & Wellness Services',
            'Child Minding',
            'Event & Wedding Planning',
            'Sports & Fitness Coaching',
            'Catering & Chef Services',
            'Construction & Property Services',
            'Web & App Development',
            'Waste Management',
        ];

        foreach ($categories as $category) {
            ServiceCategory::updateOrCreate(
                ['name' => $category],
                ['is_active' => true]
            );
        }
    }
}
