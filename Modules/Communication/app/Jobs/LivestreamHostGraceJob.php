<?php

declare(strict_types=1);

namespace Modules\Communication\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Services\CallService;

/**
 * When the LIVESTREAM host disconnects (dead phone / lost network), we do NOT
 * end the stream instantly. Instead we wait a grace period (default 20s). If the
 * host reconnects (joinStream) within that window, the stream stays live. If not,
 * the stream is force-ended and LivestreamEnded fires.
 */
class LivestreamHostGraceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Cache key marking a grace window is pending for a stream. */
    public const CACHE_KEY = 'livestream:host_grace:';

    public function __construct(
        public readonly int $streamId,
        public readonly int $hostId
    ) {}

    public function handle(CallService $callService): void
    {
        // Host already rejoined during the grace window (key was forgotten) -> cancel.
        if (! Cache::has(self::CACHE_KEY.$this->streamId)) {
            Log::info("Livestream grace cancelled (host rejoined) stream={$this->streamId}");

            return;
        }

        // Host didn't rejoin. Clear cache and end stream.
        Cache::forget(self::CACHE_KEY.$this->streamId);

        Log::info("Livestream grace expired, ending stream={$this->streamId}");

        // Safe even if already ended: endStream guards on call_type + host.
        $callService->endStreamSafely($this->streamId, $this->hostId);
    }
}
