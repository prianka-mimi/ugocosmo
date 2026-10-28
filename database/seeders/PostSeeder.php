<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\PostSave;
use App\Models\PostShare;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class PostSeeder extends Seeder
{
    public function run(): void
    {
        $p = $this->profiles();

        $posts = [
            ['author' => 'alice', 'content' => 'Just launched my new brand identity service! 🎨 Check out my portfolio and let me know what you think. DM me for a free 15-min consultation.'],
            ['author' => 'alice', 'content' => 'Tip for designers: always start with the brief, not the tool. Understanding the client\'s audience is 80% of the work. 💡'],
            ['author' => 'bob',   'content' => 'Excited to announce our new API product is live! Built with Laravel 11 & love ❤️ Check the link in bio.'],
            ['author' => 'bob',   'content' => 'Hot take: clean code is not about fewer lines — it\'s about fewer surprises. What do you think? 🤔'],
            ['author' => 'carol', 'content' => 'Toronto Networking Night tickets are now available! 🎉 Join 200+ professionals for an evening of connections. Link in bio.'],
            ['author' => 'carol', 'content' => 'Behind the scenes of last night\'s gala dinner. 500 guests, 3 months of planning, and it all came together perfectly! 🥂'],
            ['author' => 'eva',   'content' => 'Golden hour shots from last weekend\'s portrait session. Photography is pure magic. 📸 #portrait #photography'],
            ['author' => 'eva',   'content' => 'New preset pack just dropped! 20 Lightroom presets for portrait photographers. Link in bio. 🎞️'],
            ['author' => 'frank', 'content' => 'The biggest mistake startups make: building before validating. Talk to 10 customers before writing a single line of code. 🚀'],
            ['author' => 'grace', 'content' => 'Monday motivation: your only competition is who you were yesterday. Let\'s get moving! 💪 #fitness #motivation'],
            ['author' => 'henry', 'content' => 'New beat pack just dropped — 10 exclusive trap/R&B beats. Perfect for your next project. 🎵 Link in bio.'],
            ['author' => 'isla',  'content' => 'Finished this living room transformation today. The client was in tears (happy ones!). Interior design is so rewarding. 🏠'],
            ['author' => 'jack',  'content' => 'E-commerce tip: your product page is your salesperson. Invest in great photos and clear copy. 📦'],
            ['author' => 'david', 'content' => 'Great read on digital marketing trends in 2025. AI-driven personalisation is going to change everything. 📊'],
            ['author' => 'david', 'content' => 'Just connected with some amazing professionals on here. This platform is incredible for networking! 🌐'],
        ];

        $allProfiles = array_values($p);

        foreach ($posts as $i => $postData) {
            $profile = $p[$postData['author']] ?? null;
            if (! $profile) {
                continue;
            }

            $post = Post::firstOrCreate(['author_id' => $profile->id, 'content' => $postData['content']], [
                'author_id' => $profile->id,
                'content' => $postData['content'],
                'is_active' => true,
                'like_count' => 0,
                'comment_count' => 0,
                'share_count' => 0,
            ]);

            // Add 3 likes from different users
            $likers = array_filter($allProfiles, fn ($pr) => $pr->id !== $profile->id);
            $likers = array_slice(array_values($likers), $i % 3, 3);
            foreach ($likers as $liker) {
                $created = PostLike::firstOrCreate(['post_id' => $post->id, 'user_id' => $liker->id]);
                if ($created->wasRecentlyCreated) {
                    $post->increment('like_count');
                }
            }

            // Add 2 comments
            $commenters = array_slice(array_values(array_filter($allProfiles, fn ($pr) => $pr->id !== $profile->id)), ($i + 1) % 5, 2);
            $comments = ['Great post! Really insightful. 👏', 'Thanks for sharing this! Very helpful.'];
            foreach ($commenters as $j => $commenter) {
                $c = PostComment::firstOrCreate(['post_id' => $post->id, 'author_id' => $commenter->id], [
                    'post_id' => $post->id,
                    'author_id' => $commenter->id,
                    'content' => $comments[$j] ?? 'Love this! 🔥',
                ]);
                if ($c->wasRecentlyCreated) {
                    $post->increment('comment_count');
                }
            }

            // Add a save
            $saver = $allProfiles[($i + 2) % count($allProfiles)];
            if ($saver->id !== $profile->id) {
                PostSave::firstOrCreate(['post_id' => $post->id, 'user_id' => $saver->id]);
            }

            // Add a share
            $sharer = $allProfiles[($i + 3) % count($allProfiles)];
            if ($sharer->id !== $profile->id) {
                PostShare::firstOrCreate(['post_id' => $post->id, 'user_id' => $sharer->id], [
                    'post_id' => $post->id,
                    'user_id' => $sharer->id,
                ]);
                $post->increment('share_count');
            }
        }
    }

    private function profiles(): array
    {
        $map = [];
        foreach (['alice', 'bob', 'carol', 'david', 'eva', 'frank', 'grace', 'henry', 'isla', 'jack'] as $u) {
            $pr = Profile::whereHas('user', fn ($q) => $q->where('username', $u))->first();
            if ($pr) {
                $map[$u] = $pr;
            }
        }

        return $map;
    }
}
