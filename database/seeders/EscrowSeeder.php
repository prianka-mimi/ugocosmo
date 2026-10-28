<?php

namespace Database\Seeders;

use App\Models\EscrowPayment;
use App\Models\Order;
use App\Models\Profile;
use App\Models\SellerBankAccount;
use Illuminate\Database\Seeder;

class EscrowSeeder extends Seeder
{
    public function run(): void
    {
        $sellers = ['alice', 'eva', 'henry', 'jack', 'grace', 'carol'];

        $bankData = [
            'alice' => ['bank_name' => 'Chase Bank',    'account_name' => 'Alice Johnson',  'account_number' => '****1234', 'sort_code' => '12-34-56'],
            'eva' => ['bank_name' => 'Deutsche Bank', 'account_name' => 'Eva Martinez',   'account_number' => '****5678', 'sort_code' => '23-45-67'],
            'henry' => ['bank_name' => 'Bank of America', 'account_name' => 'Henry Wilson',  'account_number' => '****9012', 'sort_code' => '34-56-78'],
            'jack' => ['bank_name' => 'DBS Bank',      'account_name' => 'Jack Davis',     'account_number' => '****3456', 'sort_code' => '45-67-89'],
            'grace' => ['bank_name' => 'Wells Fargo',   'account_name' => 'Grace Kim',      'account_number' => '****7890', 'sort_code' => '56-78-90'],
            'carol' => ['bank_name' => 'TD Bank',       'account_name' => 'Carol Williams', 'account_number' => '****2345', 'sort_code' => '67-89-01'],
        ];

        foreach ($sellers as $username) {
            $profile = Profile::whereHas('user', fn ($q) => $q->where('username', $username))->first();
            if (! $profile) {
                continue;
            }

            $bank = $bankData[$username];
            SellerBankAccount::firstOrCreate(['seller_id' => $profile->id], array_merge($bank, [
                'seller_id' => $profile->id,
                'is_primary' => true,
                'is_verified' => true,
            ]));

            // Create escrow for each completed order where this user is seller
            Order::where('seller_id', $profile->id)->where('status', 'completed')->each(function ($order) {
                EscrowPayment::firstOrCreate(['order_id' => $order->id], [
                    'order_id' => $order->id,
                    'amount' => $order->total,
                    'payment_reference' => 'ESC-'.strtoupper(substr(md5($order->id), 0, 8)),
                    'status' => 'released',
                    'buyer_id' => $order->buyer_id,
                    'seller_id' => $order->seller_id,
                    'payment_received_at' => now()->subDays(5),
                    'escrow_released_at' => now()->subDays(2),
                ]);
            });
        }
    }
}
