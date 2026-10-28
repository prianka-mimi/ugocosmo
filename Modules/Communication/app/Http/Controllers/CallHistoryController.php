<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Communication\Services\CallService;
use Modules\Communication\Traits\ApiResponseTrait;
use Throwable;

class CallHistoryController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly CallService $callService) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->integer('per_page', 20);
            $history = $this->callService->paginateCallHistory($request->user(), $perPage);

            return $this->sendResponse($history, 'Call history retrieved successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve call history.', 500);
        }
    }
}
