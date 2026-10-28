<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\Story;
use App\Models\StoryReaction;
use App\Models\StoryView;
use Illuminate\Database\Seeder;

class StorySeeder extends Seeder
{
    public function run(): void
    {
        $profiles = Profile::whereHas('user', fn ($q) => $q->whereIn('email', [
            'alice@example.com', 'eva@example.com', 'carol@example.com',
        ]))->get();

        foreach ($profiles as $profile) {
            $story = Story::firstOrCreate(['creator_id' => $profile->id, 'media_type' => 'image'], [
                'creator_id' => $profile->id,
                'media_url' => 'https://picsum.photos/seed/'.$profile->id.'/800/600',
                'media_type' => 'image',
                'caption' => 'Sharing a moment from my day ✨',
                'duration_seconds' => 5,
                'expires_at' => now()->addHours(24),
                'view_count' => 0,
            ]);

            // Add a view from another profile
            $viewer = Profile::where('id', '!=', $profile->id)->first();
            if ($viewer) {
                StoryView::firstOrCreate(['story_id' => $story->id, 'viewer_id' => $viewer->id], [
                    'story_id' => $story->id,
                    'viewer_id' => $viewer->id,
                    'viewed_at' => now(),
                ]);
                $story->increment('view_count');
            }

            // Add a reaction
            $reactor = Profile::where('id', '!=', $profile->id)->skip(1)->first();
            if ($reactor) {
                StoryReaction::firstOrCreate(['story_id' => $story->id, 'reactor_id' => $reactor->id], [
                    'story_id' => $story->id,
                    'reactor_id' => $reactor->id,
                    'reaction_type' => 'like',
                ]);
            }
        }
    }
}
