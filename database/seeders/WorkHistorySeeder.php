<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkHistorySeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            ['email' => 'alice@example.com',  'company_name' => 'Creative Studio NYC',   'position_title' => 'Lead UI/UX Designer',      'start_date' => '2018-01-01', 'end_date' => '2022-06-30', 'is_currently_working' => false, 'job_description' => 'Led design for 30+ client projects.'],
            ['email' => 'alice@example.com',  'company_name' => 'Freelance',              'position_title' => 'Independent Designer',     'start_date' => '2022-07-01', 'end_date' => null,          'is_currently_working' => true,  'job_description' => 'Brand identity, web & app design.'],
            ['email' => 'bob@example.com',    'company_name' => 'TechCorp Ltd',           'position_title' => 'Senior Developer',         'start_date' => '2017-03-01', 'end_date' => '2023-12-31', 'is_currently_working' => false, 'job_description' => 'Full-stack Laravel & Vue development.'],
            ['email' => 'bob@example.com',    'company_name' => 'Startup Inc',            'position_title' => 'CTO',                      'start_date' => '2024-01-01', 'end_date' => null,          'is_currently_working' => true,  'job_description' => 'Leading a team of 12 engineers.'],
            ['email' => 'carol@example.com',  'company_name' => 'Grand Events Co',        'position_title' => 'Senior Event Planner',     'start_date' => '2016-05-01', 'end_date' => '2021-12-31', 'is_currently_working' => false, 'job_description' => 'Managed 200+ corporate events.'],
            ['email' => 'carol@example.com',  'company_name' => 'Carol Events',           'position_title' => 'Founder & CEO',            'start_date' => '2022-01-01', 'end_date' => null,          'is_currently_working' => true,  'job_description' => 'Running my own event planning business.'],
            ['email' => 'eva@example.com',    'company_name' => 'Berlin Photo Agency',    'position_title' => 'Staff Photographer',       'start_date' => '2019-06-01', 'end_date' => '2023-05-31', 'is_currently_working' => false, 'job_description' => 'Commercial & editorial photography.'],
            ['email' => 'eva@example.com',    'company_name' => 'Freelance',              'position_title' => 'Photographer & Director',  'start_date' => '2023-06-01', 'end_date' => null,          'is_currently_working' => true,  'job_description' => 'Portrait, event & product photography.'],
            ['email' => 'frank@example.com',  'company_name' => 'McKinsey & Co',          'position_title' => 'Business Analyst',         'start_date' => '2015-09-01', 'end_date' => '2020-08-31', 'is_currently_working' => false, 'job_description' => 'Strategy consulting for Fortune 500.'],
            ['email' => 'frank@example.com',  'company_name' => 'Frank Consulting',       'position_title' => 'Principal Consultant',     'start_date' => '2020-09-01', 'end_date' => null,          'is_currently_working' => true,  'job_description' => 'Startup advisory & growth strategy.'],
            ['email' => 'grace@example.com',  'company_name' => 'LA Fitness Club',        'position_title' => 'Personal Trainer',         'start_date' => '2018-01-01', 'end_date' => '2022-12-31', 'is_currently_working' => false, 'job_description' => 'Trained 50+ clients weekly.'],
            ['email' => 'grace@example.com',  'company_name' => 'Freelance',              'position_title' => 'Online Fitness Coach',     'start_date' => '2023-01-01', 'end_date' => null,          'is_currently_working' => true,  'job_description' => 'Online coaching & nutrition plans.'],
            ['email' => 'henry@example.com',  'company_name' => 'Sound Wave Studios',     'position_title' => 'Audio Engineer',           'start_date' => '2016-04-01', 'end_date' => '2022-03-31', 'is_currently_working' => false, 'job_description' => 'Mixed & mastered 100+ tracks.'],
            ['email' => 'henry@example.com',  'company_name' => 'Freelance',              'position_title' => 'Music Producer',           'start_date' => '2022-04-01', 'end_date' => null,          'is_currently_working' => true,  'job_description' => 'Producing beats & full albums.'],
            ['email' => 'isla@example.com',   'company_name' => 'Maison Design Paris',    'position_title' => 'Interior Designer',        'start_date' => '2017-02-01', 'end_date' => '2023-01-31', 'is_currently_working' => false, 'job_description' => 'Luxury residential & commercial design.'],
            ['email' => 'isla@example.com',   'company_name' => 'Isla Interiors',         'position_title' => 'Founder',                  'start_date' => '2023-02-01', 'end_date' => null,          'is_currently_working' => true,  'job_description' => 'Boutique interior design studio.'],
        ];

        foreach ($entries as $entry) {
            $user = User::where('email', $entry['email'])->first();
            if (! $user) {
                continue;
            }
            unset($entry['email']);
            $entry['user_id'] = $user->id;
            DB::table('work_histories')->insertOrIgnore($entry + ['created_at' => now(), 'updated_at' => now()]);
        }
    }
}
