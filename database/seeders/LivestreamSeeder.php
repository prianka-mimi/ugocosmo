<?php

namespace Database\Seeders;

use App\Models\Livestream;
use App\Models\LivestreamComment;
use App\Models\LivestreamGift;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class LivestreamSeeder extends Seeder
{
    public function run(): void
    {
        $alice = Profile::whereHas('user', fn ($q) => $q->where('email', 'alice@example.com'))->first();
        $eva = Profile::whereHas('user', fn ($q) => $q->where('email', 'eva@example.com'))->first();
        $bob = Profile::whereHas('user', fn ($q) => $q->where('email', 'bob@example.com'))->first();

        $streams = [
            ['host' => $alice, 'title' => 'Live Design Session: Brand Identity',  'description' => 'Watch me design a brand identity live!', 'is_live' => false, 'started_at' => now()->subHours(2), 'ended_at' => now()->subHour()],
            ['host' => $eva,   'title' => 'Photography Tips & Tricks',             'description' => 'Sharing my top photography secrets.',    'is_live' => true,  'started_at' => now()->subMinutes(30), 'ended_at' => null],
        ];

        foreach ($streams as $data) {
            if (! $data['host']) {
                continue;
            }

            $stream = Livestream::firstOrCreate(['title' => $data['title'], 'host_id' => $data['host']->id], [
                'host_id' => $data['host']->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'is_live' => $data['is_live'],
                'viewer_count' => rand(5, 50),
                'started_at' => $data['started_at'],
                'ended_at' => $data['ended_at'],
            ]);

            if ($bob) {
                LivestreamComment::firstOrCreate(['livestream_id' => $stream->id, 'user_id' => $bob->id], [
                    'livestream_id' => $stream->id,
                    'user_id' => $bob->id,
                    'content' => 'This is amazing! 🔥',
                ]);

                LivestreamGift::firstOrCreate(['livestream_id' => $stream->id, 'sender_id' => $bob->id], [
                    'livestream_id' => $stream->id,
                    'sender_id' => $bob->id,
                    'gift_type' => 'star',
                    'amount' => 5.00,
                    'message' => 'Keep it up!',
                ]);
            }
        }
    }
}
