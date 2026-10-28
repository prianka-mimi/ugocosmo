<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationIndexRequest;
use App\Http\Resources\NotificationResource;
use App\Manager\NotificationManager;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationController extends Controller
{
    protected NotificationManager $notificationManager;

    public function __construct(NotificationManager $notificationManager)
    {
        $this->notificationManager = $notificationManager;
    }

    public function index(NotificationIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = NotificationResource::collection($this->notificationManager->index($perPage))->response()->getData();
            $this->status_message = 'Notifications retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Notifications retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function unreadCount(): JsonResponse
    {
        try {
            $this->data = [
                'unread_count' => $this->notificationManager->unreadCount(),
            ];
            $this->status_message = 'Unread count retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Unread count error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        try {
            $this->data = NotificationResource::make($this->notificationManager->markAsRead($notification));
            $this->status_message = 'Notification marked as read';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Notification mark read error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function markAllAsRead(): JsonResponse
    {
        try {
            $count = $this->notificationManager->markAllAsRead();
            $this->data = ['marked_read_count' => $count];
            $this->status_message = 'All notifications marked as read';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Mark all read error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
