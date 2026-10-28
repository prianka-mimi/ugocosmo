<?php

namespace Database\Seeders;

use App\Models\JobListing;
use App\Models\JobQualifyingQuestion;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class JobSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = $this->profiles();

        $jobs = [
            ['poster' => 'bob',   'title' => 'Junior Laravel Developer',      'company' => 'Startup Inc',       'location' => 'Remote',          'job_type' => 'full_time',  'is_remote' => true,  'salary_min' => 40000, 'salary_max' => 60000,  'description' => 'Join our growing team as a junior Laravel developer. You will work on our SaaS platform.'],
            ['poster' => 'bob',   'title' => 'React Frontend Developer',       'company' => 'Startup Inc',       'location' => 'Remote',          'job_type' => 'contract',   'is_remote' => true,  'salary_min' => 55000, 'salary_max' => 85000,  'description' => 'Contract role for an experienced React developer to build our customer dashboard.'],
            ['poster' => 'carol', 'title' => 'Event Coordinator',              'company' => 'Carol Events',      'location' => 'Toronto, CA',     'job_type' => 'part_time',  'is_remote' => false, 'salary_min' => 22000, 'salary_max' => 35000,  'description' => 'Part-time event coordinator to assist with corporate and social events.'],
            ['poster' => 'carol', 'title' => 'Marketing & Social Media Manager', 'company' => 'Carol Events',     'location' => 'Toronto, CA',     'job_type' => 'full_time',  'is_remote' => false, 'salary_min' => 38000, 'salary_max' => 52000,  'description' => 'Manage our social media presence and marketing campaigns.'],
            ['poster' => 'frank', 'title' => 'Business Development Manager',   'company' => 'Frank Consulting',  'location' => 'Dubai, AE',       'job_type' => 'full_time',  'is_remote' => false, 'salary_min' => 60000, 'salary_max' => 90000,  'description' => 'Drive new business opportunities and manage key client relationships.'],
            ['poster' => 'frank', 'title' => 'Junior Business Analyst',        'company' => 'Frank Consulting',  'location' => 'Remote',          'job_type' => 'full_time',  'is_remote' => true,  'salary_min' => 35000, 'salary_max' => 50000,  'description' => 'Support senior consultants with research, data analysis and presentations.'],
            ['poster' => 'grace', 'title' => 'Online Fitness Coach',           'company' => 'Grace Fitness',     'location' => 'Remote',          'job_type' => 'part_time',  'is_remote' => true,  'salary_min' => 20000, 'salary_max' => 40000,  'description' => 'Deliver online personal training sessions and create custom workout plans.'],
            ['poster' => 'jack',  'title' => 'E-Commerce Operations Manager',  'company' => 'Jack Commerce',     'location' => 'Singapore, SG',   'job_type' => 'full_time',  'is_remote' => false, 'salary_min' => 45000, 'salary_max' => 65000,  'description' => 'Oversee daily e-commerce operations including inventory, logistics and customer service.'],
        ];

        foreach ($jobs as $data) {
            $profile = $profiles[$data['poster']] ?? null;
            if (! $profile) {
                continue;
            }
            unset($data['poster']);

            $job = JobListing::firstOrCreate(['title' => $data['title'], 'poster_id' => $profile->id], array_merge($data, [
                'poster_id' => $profile->id,
                'is_active' => true,
            ]));

            // Add qualifying questions
            JobQualifyingQuestion::firstOrCreate(['job_id' => $job->id, 'display_order' => 1], [
                'job_id' => $job->id,
                'question' => 'How many years of relevant experience do you have?',
                'is_required' => true,
                'expected_answer' => '2+',
                'display_order' => 1,
            ]);
            JobQualifyingQuestion::firstOrCreate(['job_id' => $job->id, 'display_order' => 2], [
                'job_id' => $job->id,
                'question' => 'Are you available to start within 2 weeks?',
                'is_required' => true,
                'expected_answer' => 'Yes',
                'display_order' => 2,
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
