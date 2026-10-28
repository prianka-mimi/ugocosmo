<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Communication\Services\GiftService;
use Modules\Communication\Traits\ApiResponseTrait;
use Throwable;

class GiftController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly GiftService $giftService) {}

    public function index(): JsonResponse
    {
        try {
            $gifts = $this->giftService->getActiveGifts()->map(static function ($gift): array {
                return [
                    'id' => (int) $gift->id,
                    'name' => (string) $gift->title,
                    'icon_url' => (string) $gift->icon_url,
                    'coins' => (int) $gift->coin_cost,
                ];
            })->values()->all();

            return $this->sendResponse($gifts, 'Gifts retrieved successfully');
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve gifts.', 500);
        }
    }
}
