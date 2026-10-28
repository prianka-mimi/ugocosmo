<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class ConversationSeeder extends Seeder
{
    public function run(): void
    {
        $alice = Profile::whereHas('user', fn ($q) => $q->where('email', 'alice@example.com'))->first();
        $bob = Profile::whereHas('user', fn ($q) => $q->where('email', 'bob@example.com'))->first();
        $carol = Profile::whereHas('user', fn ($q) => $q->where('email', 'carol@example.com'))->first();
        $david = Profile::whereHas('user', fn ($q) => $q->where('email', 'david@example.com'))->first();

        $pairs = [
            [$alice, $bob,   [['sender' => $alice, 'content' => 'Hey Bob, interested in collaborating?'], ['sender' => $bob, 'content' => 'Absolutely! Let\'s set up a call.']]],
            [$carol, $david, [['sender' => $carol, 'content' => 'Hi David, saw your marketing profile!'], ['sender' => $david, 'content' => 'Thanks Carol! Happy to connect.']]],
        ];

        foreach ($pairs as [$p1, $p2, $messages]) {
            if (! $p1 || ! $p2) {
                continue;
            }

            $conversation = Conversation::firstOrCreate(
                ['participant_1' => min($p1->id, $p2->id), 'participant_2' => max($p1->id, $p2->id)],
                ['participant_1' => min($p1->id, $p2->id), 'participant_2' => max($p1->id, $p2->id), 'last_message_at' => now()]
            );

            foreach ($messages as $msg) {
                Message::firstOrCreate(['conversation_id' => $conversation->id, 'content' => $msg['content']], [
                    'conversation_id' => $conversation->id,
                    'sender_id' => $msg['sender']->id,
                    'content' => $msg['content'],
                    'is_read' => false,
                ]);
            }
        }
    }
}
