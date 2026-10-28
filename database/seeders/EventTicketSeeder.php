<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class EventTicketSeeder extends Seeder
{
    public function run(): void
    {
        $david = Profile::whereHas('user', fn ($q) => $q->where('email', 'david@example.com'))->first();
        $bob = Profile::whereHas('user', fn ($q) => $q->where('email', 'bob@example.com'))->first();

        $events = Event::all();

        foreach ($events as $i => $event) {
            $customer = ($i % 2 === 0) ? $david : $bob;
            if (! $customer) {
                continue;
            }

            EventTicket::firstOrCreate(['event_id' => $event->id, 'customer_id' => $customer->id], [
                'event_id' => $event->id,
                'customer_id' => $customer->id,
                'ticket_number' => 'TKT-'.strtoupper(substr(md5($event->id.$customer->id), 0, 8)),
                'customer_name' => $customer->full_name ?? 'Guest',
                'status' => 'active',
                'purchased_at' => now(),
            ]);
        }
    }
}
