<?php

namespace App\View\Components;

use App\Models\Event;
use Illuminate\View\Component;

class EventJsonLd extends Component
{
    public array $schema;

    public function __construct(Event $event)
    {
        $base = rtrim((string) config('services.google_indexing.site_url', config('app.url')), '/');

        $this->schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'description' => strip_tags($event->description ?? ''),
            'startDate' => $event->event_date?->toAtomString(),
            'endDate' => $event->end_date?->toAtomString(),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => array_filter([
                '@type' => 'Place',
                'name' => $event->location,
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'addressLocality' => $event->city,
                    'addressCountry' => $event->country,
                ]),
            ]),
            'organizer' => array_filter([
                '@type' => 'Organization',
                'name' => $event->organizer?->display_name ?? config('app.name'),
                'url' => $base,
            ]),
            'offers' => array_filter([
                '@type' => 'Offer',
                'price' => $event->price ? (float) $event->price : '0',
                'priceCurrency' => strtoupper($event->currency ?? 'USD'),
                'availability' => 'https://schema.org/InStock',
                'url' => "{$base}/events/{$event->id}",
            ]),
            'image' => $event->image_url ?: null,
            'url' => "{$base}/events/{$event->id}",
        ]);
    }

    public function render()
    {
        return view('components.json-ld.event');
    }
}
