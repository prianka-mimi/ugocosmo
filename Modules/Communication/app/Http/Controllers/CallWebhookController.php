<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log as LaravelLog;
use Modules\Communication\Services\CallService;
use Modules\Communication\Traits\ApiResponseTrait;
use Throwable;

class CallWebhookController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly CallService $callService) {}

    public function handleLiveKitWebhook(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();

            LaravelLog::info('Received LiveKit webhook: '.json_encode([
                'payload' => $payload,
                'headers' => $request->headers->all(),
            ], JSON_UNESCAPED_SLASHES));
            $result = $this->callService->handleLiveKitWebhook($payload, $request->headers->all(), $request->getContent());

            return $this->sendResponse($result, 'Webhook processed successfully');
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to process LiveKit webhook.', 500);
        }
    }

    public function handleAgoraWebhook(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            LaravelLog::info('Received Agora webhook: '.json_encode([
                'payload' => $payload,
                'headers' => $request->headers->all(),
            ], JSON_UNESCAPED_SLASHES));
            $result = $this->callService->handleAgoraWebhook($payload, $request->headers->all());

            return $this->sendResponse($result, 'Webhook processed successfully');
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to process Agora webhook.', 500);
        }
    }
}
