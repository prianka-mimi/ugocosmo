<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConversationIndexRequest;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\StartConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Manager\ConversationManager;
use App\Models\Conversation;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConversationController extends Controller
{
    protected ConversationManager $conversationManager;

    public function __construct(ConversationManager $conversationManager)
    {
        $this->conversationManager = $conversationManager;
    }

    public function index(ConversationIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = $this->conversationManager->index($request->validated(), $perPage);
            $this->status_message = 'Conversations retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Conversations retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(Conversation $conversation): JsonResponse
    {
        try {
            $data = $this->conversationManager->show($conversation);
            $this->data = [
                'conversation' => ConversationResource::make($data),
                'messages' => MessageResource::collection($data->messages),
            ];
            $this->status_message = 'Conversation retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Conversation retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function start(StartConversationRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $profile = Profile::findOrFail($request->input('recipient_id'));
            $conversation = $this->conversationManager->start(
                $profile,
                $request->input('content'),
                $request->input('order_id')
            );
            DB::commit();

            $this->data = [
                'conversation' => ConversationResource::make($conversation),
                'messages' => MessageResource::collection($conversation->messages),
            ];
            $this->status_message = 'Conversation started';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Conversation start error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function sendMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        try {
            DB::beginTransaction();
            $conversation = $this->conversationManager->sendMessage($conversation, $request->input('content'));
            DB::commit();

            $this->data = [
                'conversation' => ConversationResource::make($conversation),
                'messages' => MessageResource::collection($conversation->messages),
            ];
            $this->status_message = 'Message sent';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Message send error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function markAsRead(Conversation $conversation): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->conversationManager->markAsRead($conversation);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Messages marked as read';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Mark read error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function unreadCount(): JsonResponse
    {
        try {
            $this->data = ['unread_count' => $this->conversationManager->unreadCount()];
            $this->status_message = 'Unread count retrieved';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Unread count error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
