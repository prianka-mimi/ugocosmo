<?php

namespace Database\Seeders;

use App\Models\DeviceToken;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class DeviceTokenSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = Profile::whereHas('user', fn ($q) => $q->whereIn('username', [
            'alice', 'bob', 'carol', 'david', 'eva', 'frank', 'grace', 'henry', 'isla', 'jack',
        ]))->get();

        $deviceTypes = ['android', 'ios', 'web'];

        foreach ($profiles as $i => $profile) {
            DeviceToken::firstOrCreate(['user_id' => $profile->id], [
                'user_id' => $profile->id,
                'fcm_token' => 'fcm_seed_token_'.$profile->id.'_'.md5($profile->id.'primary'),
                'device_type' => $deviceTypes[$i % 3],
                'is_active' => true,
            ]);
        }
    }
}
