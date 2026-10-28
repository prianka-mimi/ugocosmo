<?php

namespace App\Http\Controllers;

use App\Http\Requests\LivestreamAddItemRequest;
use App\Http\Requests\LivestreamIndexRequest;
use App\Http\Requests\LivestreamStoreRequest;
use App\Http\Resources\LivestreamResource;
use App\Manager\LivestreamManager;
use App\Models\Livestream;
use App\Models\LivestreamItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LivestreamController extends Controller
{
    protected LivestreamManager $livestreamManager;

    public function __construct(LivestreamManager $livestreamManager)
    {
        $this->livestreamManager = $livestreamManager;
    }

    public function live(LivestreamIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = LivestreamResource::collection($this->livestreamManager->live($request->validated(), $perPage))->response()->getData();
            $this->status_message = 'Live streams retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Live streams retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function mine(LivestreamIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = LivestreamResource::collection($this->livestreamManager->mine($request->validated(), $perPage))->response()->getData();
            $this->status_message = 'Your livestreams retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My livestreams retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function start(LivestreamStoreRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = LivestreamResource::make($this->livestreamManager->start($request->validated()));
            DB::commit();

            $this->status_message = 'Livestream started successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream start error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(Livestream $livestream): JsonResponse
    {
        try {
            $this->data = LivestreamResource::make($this->livestreamManager->show($livestream));
            $this->status_message = 'Livestream retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Livestream retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function stop(Livestream $livestream): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = LivestreamResource::make($this->livestreamManager->stop($livestream));
            DB::commit();

            $this->status_message = 'Livestream ended successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream stop error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function addItem(LivestreamAddItemRequest $request, Livestream $livestream): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->livestreamManager->addItem($livestream, $request->validated());
            DB::commit();

            $this->status_message = 'Item added to livestream';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream add item error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function removeItem(Livestream $livestream, LivestreamItem $item): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->livestreamManager->removeItem($livestream, $item);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Item removed from livestream';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream remove item error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
