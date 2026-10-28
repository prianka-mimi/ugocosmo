<?php

declare(strict_types=1);

namespace Modules\Communication\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Services\CallService;

class HandleMiscallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly array $payload) {}

    public function handle(CallService $callService): void
    {
        $callId = $this->payload['call_id'] ?? 0;

        Log::info("Handling miscall for call ID: {$callId}");

        $callService->closeTimedOutOngoingCallById((int) $callId);
    }
}
