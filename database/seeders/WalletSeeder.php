<?php

namespace Database\Seeders;

use App\Models\GiftWallet;
use App\Models\Profile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WalletSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = Profile::whereHas('user', fn ($q) => $q->whereIn('email', [
            'alice@example.com', 'bob@example.com', 'carol@example.com', 'david@example.com', 'eva@example.com',
        ]))->get();

        foreach ($profiles as $profile) {
            $wallet = GiftWallet::firstOrCreate(['user_id' => $profile->id], [
                'user_id' => $profile->id,
                'balance' => 100.00,
                'total_deposited' => 100.00,
                'total_withdrawn' => 0.00,
                'total_gifted' => 0.00,
                'total_received' => 0.00,
            ]);

            // Seed a top-up transaction
            $exists = DB::table('gift_wallet_transactions')->where('wallet_id', $wallet->id)->where('type', 'top_up')->exists();
            if (! $exists) {
                DB::table('gift_wallet_transactions')->insert([
                    'wallet_id' => $wallet->id,
                    'user_id' => $profile->id,
                    'type' => 'top_up',
                    'amount' => 100.00,
                    'balance_after' => 100.00,
                    'reference' => 'SEED-'.strtoupper(substr(md5($profile->id), 0, 8)),
                    'description' => 'Initial wallet top-up',
                    'status' => 'completed',
                    'payment_method' => 'card',
                    'completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
