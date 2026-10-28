<?php

namespace App\Jobs;

use App\Services\GoogleIndexingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GoogleIndexingNotifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  string  $url  The canonical public URL to notify Google about
     * @param  string  $type  'URL_UPDATED' | 'URL_DELETED'
     */
    public function __construct(
        public string $url,
        public string $type,
    ) {
        $this->onQueue('high');
    }

    public function handle(GoogleIndexingService $indexing): void
    {
        try {
            match ($this->type) {
                'URL_DELETED' => $indexing->notifyDeleted($this->url),
                default => $indexing->notifyUpdated($this->url),
            };
        } catch (\Throwable $e) {
            Log::error('GoogleIndexingNotifyJob failed', [
                'url' => $this->url,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
