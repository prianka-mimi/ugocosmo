<?php

namespace App\Observers;

use App\Jobs\GoogleIndexingNotifyJob;
use App\Models\Event;

/**
 * Auto-notifies Google Indexing API when an Event changes.
 *
 * Active + published events trigger URL_UPDATED.
 * Unpublished, deactivated, or deleted events trigger URL_DELETED.
 */
class EventObserver
{
    public function created(Event $event): void
    {
        if ($this->shouldIndex($event)) {
            GoogleIndexingNotifyJob::dispatch($this->url($event), 'URL_UPDATED');
        }
    }

    public function updated(Event $event): void
    {
        $wasIndexable = $event->getOriginal('is_active') && $event->getOriginal('is_published');

        if ($wasIndexable && ! $this->shouldIndex($event)) {
            GoogleIndexingNotifyJob::dispatch($this->url($event), 'URL_DELETED');

            return;
        }

        if ($this->shouldIndex($event)) {
            GoogleIndexingNotifyJob::dispatch($this->url($event), 'URL_UPDATED');
        }
    }

    public function deleted(Event $event): void
    {
        GoogleIndexingNotifyJob::dispatch($this->url($event), 'URL_DELETED');
    }

    protected function shouldIndex(Event $event): bool
    {
        return $event->is_active && $event->is_published && ! empty($event->title);
    }

    protected function url(Event $event): string
    {
        $base = rtrim((string) config('services.google_indexing.site_url', config('app.url')), '/');

        return "{$base}/events/{$event->id}";
    }
}
