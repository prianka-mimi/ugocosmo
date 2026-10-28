<?php

namespace Database\Seeders;

use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class JobApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $david = Profile::whereHas('user', fn ($q) => $q->where('email', 'david@example.com'))->first();
        $alice = Profile::whereHas('user', fn ($q) => $q->where('email', 'alice@example.com'))->first();

        $jobs = JobListing::all();

        foreach ($jobs as $job) {
            $applicant = ($job->poster_id === $david?->id) ? $alice : $david;
            if (! $applicant) {
                continue;
            }

            JobApplication::firstOrCreate(['job_id' => $job->id, 'applicant_id' => $applicant->id], [
                'job_id' => $job->id,
                'applicant_id' => $applicant->id,
                'status' => 'pending',
                'cover_letter' => 'I am very interested in this position and believe my skills are a great match.',
                'is_qualified' => true,
            ]);
        }
    }
}
