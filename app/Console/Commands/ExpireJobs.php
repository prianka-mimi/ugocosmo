<?php

namespace App\Console\Commands;

use App\Models\JobListing;
use Illuminate\Console\Command;

class ExpireJobs extends Command
{
    protected $signature = 'jobs:expire';

    protected $description = 'Mark jobs as inactive when their end_date has passed';

    public function handle(): int
    {
        $count = JobListing::where('is_active', true)
            ->where('end_date', '<', now()->startOfDay())
            ->update(['is_active' => false]);

        $this->info("Expired {$count} job listing(s).");

        return self::SUCCESS;
    }
}
