<?php

namespace App\Console\Commands;

use App\Manager\WebPushNotificationService;
use Illuminate\Console\Command;

class GenerateVapidKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vapid:generate-keys';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate VAPID public/private key pair for Web Push notifications';

    /**
     * Execute the console command.
     *
     * Outputs the keys in a format ready to copy-paste into .env.
     */
    public function handle(): int
    {
        $this->info('Generating VAPID keys...');

        try {
            $keys = WebPushNotificationService::generateVapidKeys();

            $this->newLine();
            $this->line('─── Add these to your .env file ───');
            $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
            $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");
            $this->line('VAPID_SUBJECT=mailto:admin@projectname.com');
            $this->line('─────────────────────────────────────');
            $this->newLine();

            $this->warn('⚠️  Keep your private key secret! Never commit it to version control.');
            $this->newLine();
            $this->info('VAPID keys generated successfully!');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to generate VAPID keys: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
