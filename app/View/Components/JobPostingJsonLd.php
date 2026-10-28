<?php

namespace App\View\Components;

use App\Models\JobListing;
use Illuminate\View\Component;

class JobPostingJsonLd extends Component
{
    public array $schema;

    public function __construct(JobListing $job)
    {
        $base = rtrim((string) config('services.google_indexing.site_url', config('app.url')), '/');

        $this->schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $job->title,
            'description' => nl2br(e($job->description ?? '')),
            'datePosted' => $job->created_at?->toAtomString(),
            'validThrough' => $job->end_date?->toAtomString(),
            'employmentType' => $this->mapEmploymentType($job->job_type),
            'jobLocationType' => $job->is_remote ? 'TELECOMMUTE' : null,
            'hiringOrganization' => array_filter([
                '@type' => 'Organization',
                'name' => $job->company ?? config('app.name'),
            ]),
            'jobLocation' => $job->is_remote ? null : array_filter([
                '@type' => 'Place',
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'addressLocality' => $job->location,
                ]),
            ]),
            'baseSalary' => ($job->salary_min || $job->salary_max) ? array_filter([
                '@type' => 'MonetaryAmount',
                'currency' => 'USD',
                'value' => array_filter([
                    '@type' => 'QuantitativeValue',
                    'minValue' => $job->salary_min ? (float) $job->salary_min : null,
                    'maxValue' => $job->salary_max ? (float) $job->salary_max : null,
                    'unitText' => 'YEAR',
                ]),
            ]) : null,
            'url' => "{$base}/jobs/{$job->id}",
        ]);
    }

    public function render()
    {
        return view('components.json-ld.job-posting');
    }

    private function mapEmploymentType(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'part_time', 'part-time' => 'PART_TIME',
            'contract' => 'CONTRACTOR',
            'internship' => 'INTERN',
            'temporary' => 'TEMPORARY',
            'volunteer' => 'VOLUNTEER',
            default => 'FULL_TIME',
        };
    }
}
