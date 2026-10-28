<?php

namespace App\Console\Commands;

use App\Models\AliExpressAccount;
use App\Services\AliExpressDropshipService;
use Illuminate\Console\Command;

/**
 * Pulls supplier logistics/tracking for every placed AliExpress order.
 * Runs synchronously (bounded at 200 orders per account per run) so the
 * console output reflects the real outcome; safe to schedule frequently.
 */
class AliExpressSyncTracking extends Command
{
    protected $signature = 'aliexpress:sync-tracking
        {--account= : Sync only this account ID}';

    protected $description = 'Sync supplier shipment tracking for placed AliExpress orders (auto ship/deliver local orders)';

    public function handle(AliExpressDropshipService $dropship): int
    {
        $accounts = AliExpressAccount::query()
            ->where('status', AliExpressAccount::STATUS_ACTIVE)
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No active AliExpress accounts to sync.');

            return self::SUCCESS;
        }

        $totals = ['checked' => 0, 'shipped' => 0, 'delivered' => 0, 'failed' => 0];

        foreach ($accounts as $account) {
            $summary = $dropship->syncTrackingForAccount($account);

            foreach ($summary as $key => $value) {
                $totals[$key] += $value;
            }

            $this->line("Account #{$account->id}: {$summary['checked']} checked, {$summary['shipped']} shipped, {$summary['delivered']} delivered, {$summary['failed']} failed.");
        }

        $this->info("Tracking sync finished: {$totals['checked']} checked, {$totals['shipped']} newly shipped, {$totals['delivered']} newly delivered, {$totals['failed']} failed.");

        return self::SUCCESS;
    }
}
