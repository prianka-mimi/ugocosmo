<?php

namespace App\Traits;

/**
 * Generates a valid schema.org JobPosting JSON-LD array for a JobListing model.
 *
 * Usage (in a Blade view or controller):
 *   $schema = $job->jobPostingJsonLd();
 *   // Then in Blade: {!! $job->jobPostingJsonLdScript() !!}
 *
 * Schema reference: https://schema.org/JobPosting
 * Google requirements: https://developers.google.com/search/docs/appearance/structured-data/job-posting
 */
trait GeneratesJobPostingJsonLd
{
    /**
     * Build the JobPosting schema.org array.
     *
     * @return array<string, mixed>
     */
    public function jobPostingJsonLd(): array
    {
        $domain = rtrim((string) config('services.google_indexing.site_domain', config('app.url')), '/');
        $url = "{$domain}/jobs/{$this->id}";

        $schema = [
            '@context' => 'https://schema.org/',
            '@type' => 'JobPosting',
            'title' => (string) ($this->title ?? ''),
            'description' => $this->resolveJobDescription(),
            'datePosted' => $this->created_at?->toAtomString(),
            'hiringOrganization' => [
                '@type' => 'Organization',
                'name' => (string) ($this->company ?? config('app.name')),
            ],
            'jobLocation' => $this->resolveJobLocation(),
            'url' => $url,
        ];

        // validThrough (end_date)
        if (! empty($this->end_date)) {
            $schema['validThrough'] = $this->end_date->toAtomString();
        }

        // employmentType
        if (! empty($this->job_type)) {
            $schema['employmentType'] = $this->mapEmploymentType($this->job_type);
        }

        // Remote work
        if ($this->is_remote) {
            $schema['jobLocationType'] = 'TELECOMMUTE';
        }

        // baseSalary
        $salaryMin = (float) ($this->salary_min ?? 0);
        $salaryMax = (float) ($this->salary_max ?? 0);
        if ($salaryMin > 0 || $salaryMax > 0) {
            $schema['baseSalary'] = [
                '@type' => 'MonetaryAmount',
                'currency' => 'GBP',
                'value' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => $salaryMin > 0 ? $salaryMin : null,
                    'maxValue' => $salaryMax > 0 ? $salaryMax : null,
                    'unitText' => 'YEAR',
                ],
            ];

            // Remove null values from salary
            $schema['baseSalary']['value'] = array_filter(
                $schema['baseSalary']['value'],
                fn ($v) => $v !== null
            );
        }

        return $schema;
    }

    /**
     * Return a <script type="application/ld+json"> tag ready for Blade output.
     */
    public function jobPostingJsonLdScript(): string
    {
        $json = json_encode($this->jobPostingJsonLd(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return '<script type="application/ld+json">'.$json.'</script>';
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function resolveJobDescription(): string
    {
        $desc = strip_tags((string) ($this->description ?? ''));

        return mb_substr(trim($desc), 0, 5000);
    }

    private function resolveJobLocation(): array
    {
        if ($this->is_remote) {
            return [
                '@type' => 'Place',
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressCountry' => 'GB',
                ],
            ];
        }

        return [
            '@type' => 'Place',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => (string) ($this->location ?? ''),
                'addressCountry' => 'GB',
            ],
        ];
    }

    private function mapEmploymentType(string $type): string
    {
        return match (strtolower($type)) {
            'full-time', 'full_time', 'fulltime' => 'FULL_TIME',
            'part-time', 'part_time', 'parttime' => 'PART_TIME',
            'contract', 'contractor' => 'CONTRACTOR',
            'temporary', 'temp' => 'TEMPORARY',
            'intern', 'internship' => 'INTERN',
            'volunteer' => 'VOLUNTEER',
            'per diem', 'per_diem' => 'PER_DIEM',
            'other' => 'OTHER',
            default => 'FULL_TIME',
        };
    }
}
