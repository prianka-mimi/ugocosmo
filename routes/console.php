<?php

use App\Console\Commands\AliExpressRefreshTokens;
use App\Console\Commands\AliExpressSyncCategories;
use App\Console\Commands\AliExpressSyncStock;
use App\Console\Commands\AliExpressSyncTracking;
use App\Console\Commands\ShopifyRegisterWebhooks;
use App\Console\Commands\ShopifySyncProducts;
use App\Console\Commands\SyncShipmentTracking;
use App\Models\JobListing;
use App\Services\CsvImportRecovery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('jobs:expire', function () {
    $count = JobListing::where('is_active', true)
        ->where('end_date', '<', now()->startOfDay())
        ->update(['is_active' => false]);

    $this->info("Expired {$count} job listing(s).");
})->purpose('Mark jobs as inactive when their end_date has passed');

// ═══════════════════════════════════════════════════════════════════
//  SHOPIFY SYNC (Scheduled)
// ═══════════════════════════════════════════════════════════════════

Schedule::command(ShopifySyncProducts::class)->hourly()->withoutOverlapping();
Schedule::command(ShopifyRegisterWebhooks::class, ['store' => 'all'])->daily()->withoutOverlapping();

// ═══════════════════════════════════════════════════════════════════
//  ALIEXPRESS SYNC (Scheduled)
// ═══════════════════════════════════════════════════════════════════

use App\Console\Commands\EtsyRefreshTokens;
use App\Console\Commands\EtsySyncImported;
use Illuminate\Support\Facades\Schedule;

Schedule::command(AliExpressRefreshTokens::class)->hourly()->withoutOverlapping();
Schedule::command(AliExpressSyncCategories::class)->daily()->withoutOverlapping();
Schedule::command(AliExpressSyncStock::class)->everyThirtyMinutes()->withoutOverlapping();
Schedule::command(AliExpressSyncTracking::class)->everyThirtyMinutes()->withoutOverlapping();

// ═══════════════════════════════════════════════════════════════════
//  ETSY SYNC (Scheduled)
// ═══════════════════════════════════════════════════════════════════

Schedule::command(EtsyRefreshTokens::class)->hourly()->withoutOverlapping();
Schedule::command(EtsySyncImported::class)->everyThirtyMinutes()->withoutOverlapping();

// ═══════════════════════════════════════════════════════════════════
//  CSV IMPORT (Scheduled watchdog)
// ═══════════════════════════════════════════════════════════════════

Artisan::command('csv:recover-stuck-imports', function () {
    $recovered = app(CsvImportRecovery::class)->recover();

    foreach ($recovered as $importId) {
        $this->info("Re-dispatched stuck CSV import #{$importId}.");
    }
})->purpose('Re-dispatch CSV imports whose queued job was lost or misrouted (every 5 minutes)')
    ->everyFiveMinutes();

// ═══════════════════════════════════════════════════════════════════
//  SHIPENGINE TRACKING SYNC (Scheduled)
// ═══════════════════════════════════════════════════════════════════

Schedule::command(SyncShipmentTracking::class)->everyThirtyMinutes()->withoutOverlapping();
