<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventIndexRequest;
use App\Http\Requests\EventStoreRequest;
use App\Http\Requests\EventUpdateRequest;
use App\Http\Resources\EventResource;
use App\Manager\EventManager;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventController extends Controller
{
    protected EventManager $eventManager;

    public function __construct(EventManager $eventManager)
    {
        $this->eventManager = $eventManager;
    }

    public function index(EventIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = EventResource::collection($this->eventManager->index($request->validated(), $perPage))->response()->getData();
            $this->status_message = 'Events retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Events retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function mine(Request $request): JsonResponse
    {
        try {
            $this->data = $this->eventManager->mine($request->only('search'));
            $this->status_message = 'Your events retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My events retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function store(EventStoreRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = EventResource::make($this->eventManager->store($request->validated()));
            DB::commit();

            $this->status_message = 'Event created successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Event creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(Event $event): JsonResponse
    {
        try {
            $this->data = EventResource::make($this->eventManager->show($event));
            $this->status_message = 'Event retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Event retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function update(EventUpdateRequest $request, Event $event): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = EventResource::make($this->eventManager->update($event, $request->validated()));
            DB::commit();

            $this->status_message = 'Event updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Event update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function destroy(Event $event): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->eventManager->destroy($event);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Event deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Event deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
