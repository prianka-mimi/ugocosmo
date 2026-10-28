<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class TestE2EFlowCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'test:flow {--role=all : Run flow for seller, buyer, or all}';

    /**
     * The console command description.
     */
    protected $description = 'Run Seller and Buyer E2E flows and save Postman logs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info("========================================================================");
        $this->info("  🚀 RUNNING UGOCOSMO E-COMMERCE END-TO-END FLOW AUTOMATION");
        $this->info("========================================================================\n");

        $role = strtolower((string) ($this->option('role') ?: 'all'));

        $flows = match ($role) {
            'seller' => [
                ['name' => 'Seller Flow', 'class' => 'SellerE2EFlowTest', 'file' => 'seller_test_flow_results.txt'],
            ],
            'buyer' => [
                ['name' => 'Buyer Flow', 'class' => 'BuyerE2EFlowTest', 'file' => 'buyer_test_flow_results.txt'],
            ],
            default => [
                ['name' => 'Seller Flow', 'class' => 'SellerE2EFlowTest', 'file' => 'seller_test_flow_results.txt'],
                ['name' => 'Buyer Flow', 'class' => 'BuyerE2EFlowTest', 'file' => 'buyer_test_flow_results.txt'],
            ],
        };

        foreach ($flows as $flow) {
            $this->comment("▶ Running {$flow['name']} ({$flow['class']})...");
            $this->line("  Output log: {$flow['file']}\n");

            // Reset log file for fresh output
            if (file_exists(base_path($flow['file']))) {
                @unlink(base_path($flow['file']));
            }

            // Execute PHPUnit test suite for the flow
            $process = new Process([
                PHP_BINARY,
                'vendor/bin/phpunit',
                '--filter=' . $flow['class'],
                '--testdox',
            ], base_path(), ['TEST_VERBOSE' => '1']);

            $process->setTimeout(300);
            $process->run(function ($type, $buffer) {
                $this->output->write($buffer);
            });

            if (! $process->isSuccessful()) {
                $this->error("\n❌ {$flow['name']} failed! Check the output above.");
                return 1;
            }

            $this->info("\n✓ {$flow['name']} passed successfully! (100%)\n");
        }

        $this->info("========================================================================");
        $this->info("  🏁 ALL REQUESTED FLOWS COMPLETED SUCCESSFULLY (100%)");
        $this->info("========================================================================\n");

        return 0;
    }
}
