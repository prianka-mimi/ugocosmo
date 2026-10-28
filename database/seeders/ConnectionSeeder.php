<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\UserConnection;
use Illuminate\Database\Seeder;

class ConnectionSeeder extends Seeder
{
    public function run(): void
    {
        $p = $this->profiles();

        // [requester, recipient, type, status]
        $connections = [
            ['alice', 'bob',   'connection', 'accepted'],
            ['alice', 'carol', 'connection', 'accepted'],
            ['alice', 'eva',   'connection', 'accepted'],
            ['bob',   'carol', 'connection', 'accepted'],
            ['bob',   'frank', 'connection', 'accepted'],
            ['carol', 'david', 'connection', 'accepted'],
            ['carol', 'grace', 'connection', 'accepted'],
            ['david', 'eva',   'connection', 'accepted'],
            ['eva',   'isla',  'connection', 'accepted'],
            ['frank', 'jack',  'connection', 'accepted'],
            ['grace', 'henry', 'connection', 'accepted'],
            ['henry', 'jack',  'connection', 'accepted'],
            ['isla',  'alice', 'connection', 'accepted'],
            ['jack',  'david', 'connection', 'pending'],
            ['frank', 'alice', 'connection', 'pending'],
            // Follows
            ['david', 'alice', 'follow', 'accepted'],
            ['david', 'bob',   'follow', 'accepted'],
            ['david', 'eva',   'follow', 'accepted'],
            ['jack',  'alice', 'follow', 'accepted'],
            ['jack',  'henry', 'follow', 'accepted'],
            ['henry', 'eva',   'follow', 'accepted'],
            ['grace', 'alice', 'follow', 'accepted'],
            ['bob',   'eva',   'follow', 'accepted'],
        ];

        foreach ($connections as [$req, $rec, $type, $status]) {
            $a = $p[$req] ?? null;
            $b = $p[$rec] ?? null;
            if (! $a || ! $b) {
                continue;
            }
            UserConnection::firstOrCreate(['requester_id' => $a->id, 'recipient_id' => $b->id], [
                'requester_id' => $a->id,
                'recipient_id' => $b->id,
                'connection_type' => $type,
                'status' => $status,
            ]);
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
