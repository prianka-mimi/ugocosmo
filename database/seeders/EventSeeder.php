<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventSeat;
use App\Models\EventSection;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        $carol = Profile::whereHas('user', fn ($q) => $q->where('email', 'carol@example.com'))->first();
        $eva = Profile::whereHas('user', fn ($q) => $q->where('email', 'eva@example.com'))->first();

        $events = [
            [
                'organizer_id' => $carol?->id,
                'title' => 'Toronto Business Networking Night',
                'description' => 'An evening of networking for local entrepreneurs.',
                'location' => 'Toronto Convention Centre',
                'event_date' => now()->addDays(30),
                'end_date' => now()->addDays(30)->addHours(4),
                'price' => 25.00,
                'max_attendees' => 200,
                'sections' => [
                    ['section_name' => 'General Admission', 'price' => 25.00, 'seats' => ['G1', 'G2', 'G3', 'G4', 'G5']],
                    ['section_name' => 'VIP',               'price' => 75.00, 'seats' => ['V1', 'V2', 'V3']],
                ],
            ],
            [
                'organizer_id' => $eva?->id,
                'title' => 'Photography Masterclass Berlin',
                'description' => 'Full-day photography workshop for all skill levels.',
                'location' => 'Berlin Art Studio',
                'event_date' => now()->addDays(45),
                'end_date' => now()->addDays(45)->addHours(8),
                'price' => 120.00,
                'max_attendees' => 30,
                'sections' => [
                    ['section_name' => 'Standard', 'price' => 120.00, 'seats' => ['S1', 'S2', 'S3', 'S4', 'S5']],
                ],
            ],
        ];

        foreach ($events as $data) {
            if (! $data['organizer_id']) {
                continue;
            }
            $sections = $data['sections'];
            unset($data['sections']);

            $event = Event::firstOrCreate(['title' => $data['title'], 'organizer_id' => $data['organizer_id']], array_merge($data, ['is_active' => true]));

            foreach ($sections as $sectionData) {
                $seats = $sectionData['seats'];
                unset($sectionData['seats']);

                $section = EventSection::firstOrCreate(['event_id' => $event->id, 'section_name' => $sectionData['section_name']], array_merge($sectionData, ['event_id' => $event->id]));

                foreach ($seats as $seatNumber) {
                    EventSeat::firstOrCreate(['section_id' => $section->id, 'seat_number' => $seatNumber], [
                        'section_id' => $section->id,
                        'seat_number' => $seatNumber,
                        'status' => 'available',
                    ]);
                }
            }
        }
    }
}
