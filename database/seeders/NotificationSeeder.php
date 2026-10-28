<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = Profile::whereHas('user', fn ($q) => $q->whereIn('email', [
            'alice@example.com', 'bob@example.com', 'carol@example.com', 'david@example.com', 'eva@example.com',
        ]))->get();

        $templates = [
            ['type' => 'connection_request', 'title' => 'New Connection Request',  'message' => 'Someone wants to connect with you.'],
            ['type' => 'order_placed',        'title' => 'New Order Received',      'message' => 'You have received a new order.'],
            ['type' => 'booking_confirmed',   'title' => 'Booking Confirmed',       'message' => 'Your booking has been confirmed.'],
            ['type' => 'message_received',    'title' => 'New Message',             'message' => 'You have a new message.'],
        ];

        foreach ($profiles as $profile) {
            foreach ($templates as $tpl) {
                Notification::firstOrCreate(['user_id' => $profile->id, 'type' => $tpl['type']], array_merge($tpl, [
                    'user_id' => $profile->id,
                    'is_read' => false,
                    'data' => null,
                ]));
            }

            NotificationPreference::firstOrCreate(['user_id' => $profile->id], [
                'user_id' => $profile->id,
                'preferences' => json_encode([
                    'booking' => ['push' => true, 'in_app' => true],
                    'ticket' => ['push' => true, 'in_app' => true],
                    'message' => ['push' => true, 'in_app' => true],
                    'livestream' => ['push' => true, 'in_app' => true],
                ]),
            ]);
        }
    }
}
