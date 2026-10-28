<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProfileSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['email' => 'alice@example.com',  'bio' => 'Freelance UI/UX designer with 6 years of experience.',     'location' => 'New York, US',   'is_seller' => true],
            ['email' => 'bob@example.com',    'bio' => 'Full-stack developer specialising in Laravel & React.',     'location' => 'London, UK',     'is_seller' => true],
            ['email' => 'carol@example.com',  'bio' => 'Professional event planner & corporate organiser.',         'location' => 'Toronto, CA',    'is_seller' => true],
            ['email' => 'david@example.com',  'bio' => 'Digital marketing specialist & content strategist.',        'location' => 'Sydney, AU',     'is_seller' => false],
            ['email' => 'eva@example.com',    'bio' => 'Award-winning photographer & videographer.',                'location' => 'Berlin, DE',     'is_seller' => true],
            ['email' => 'frank@example.com',  'bio' => 'Business consultant & startup advisor.',                    'location' => 'Dubai, AE',      'is_seller' => true],
            ['email' => 'grace@example.com',  'bio' => 'Certified personal trainer & nutrition coach.',             'location' => 'Los Angeles, US', 'is_seller' => true],
            ['email' => 'henry@example.com',  'bio' => 'Music producer & audio engineer.',                         'location' => 'Nashville, US',  'is_seller' => true],
            ['email' => 'isla@example.com',   'bio' => 'Interior designer & home staging expert.',                  'location' => 'Paris, FR',      'is_seller' => true],
            ['email' => 'jack@example.com',   'bio' => 'E-commerce entrepreneur & dropshipping expert.',            'location' => 'Singapore, SG',  'is_seller' => true],
        ];

        foreach ($data as $row) {
            $user = User::where('email', $row['email'])->first();
            if (! $user) {
                continue;
            }

            $profile = Profile::firstOrCreate(['user_id' => $user->id], [
                'email' => $row['email'],
                'full_name' => $user->name,
                'username' => $user->username,
                'bio' => $row['bio'],
                'location' => $row['location'],
                'is_seller' => $row['is_seller'],
                'website' => 'https://example.com/'.$user->username,
            ]);

            // Privacy settings per user
            DB::table('privacy_settings')->insertOrIgnore([
                'user_id' => $user->id,
                'age_visibility' => 'verified',
                'employment_history_visibility' => 'verified',
                'qualifications_visibility' => 'verified',
                'profile_visibility' => 'public',
                'show_online_status' => true,
                'message_permission' => 'everyone',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
