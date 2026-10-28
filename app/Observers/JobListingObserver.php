<?php

namespace App\Observers;

use App\Jobs\GoogleIndexingNotifyJob;
use App\Models\JobListing;

/**
 * Auto-notifies Google Indexing API when a JobListing changes.
 *
 * Only active, published jobs are submitted for indexing.
 * Inactive or deleted jobs trigger URL_DELETED.
 */
class JobListingObserver
{
    public function created(JobListing $job): void
    {
        if ($this->shouldIndex($job)) {
            GoogleIndexingNotifyJob::dispatch($this->url($job), 'URL_UPDATED');
        }
    }

    public function updated(JobListing $job): void
    {
        $wasActive = $job->getOriginal('is_active');

        if ($wasActive && ! $job->is_active) {
            GoogleIndexingNotifyJob::dispatch($this->url($job), 'URL_DELETED');

            return;
        }

        if ($this->shouldIndex($job)) {
            GoogleIndexingNotifyJob::dispatch($this->url($job), 'URL_UPDATED');
        }
    }

    public function deleted(JobListing $job): void
    {
        GoogleIndexingNotifyJob::dispatch($this->url($job), 'URL_DELETED');
    }

    protected function shouldIndex(JobListing $job): bool
    {
        return $job->is_active && ! empty($job->title);
    }

    protected function url(JobListing $job): string
    {
        $base = rtrim((string) config('services.google_indexing.site_url', config('app.url')), '/');

        return "{$base}/jobs/{$job->id}";
    }
}
