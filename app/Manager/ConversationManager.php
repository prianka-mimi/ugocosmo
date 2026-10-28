<?php

namespace App\Manager;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\NotificationPreference;
use App\Models\Profile;
use Illuminate\Support\Facades\Auth;

class ConversationManager
{
    protected NotificationManager $notificationManager;

    protected EmailNotificationService $emailService;

    public function __construct(NotificationManager $notificationManager, EmailNotificationService $emailService)
    {
        $this->notificationManager = $notificationManager;
        $this->emailService = $emailService;
    }

    public function index(array $params = [], int $perPage = 20): array
    {
        $profileId = (int) $this->profileId();

        return Conversation::forParticipant($profileId)
            ->latestFirst()
            ->with(['participantOne', 'participantTwo', 'latestMessage'])
            ->withCount(['messages as unread_count' => function ($q) use ($profileId) {
                $q->where('is_read', false)->where('sender_id', '!=', $profileId);
            }])
            ->get()
            ->map(fn (Conversation $conversation) => $this->formatConversation($conversation, $profileId))
            ->values()
            ->all();
    }

    public function show(Conversation $conversation): Conversation
    {
        $this->ensureParticipant($conversation);

        return $conversation->load(['participantOne', 'participantTwo', 'messages.sender']);
    }

    /**
     * Start a new conversation or continue an existing one.
     */
    public function start(Profile $recipient, string $content, ?int $orderId = null): Conversation
    {
        $profileId = (int) $this->profileId();

        if ((int) $recipient->id === $profileId) {
            throw new \Exception('Cannot start a conversation with yourself', 422);
        }

        $existing = Conversation::where(function ($q) use ($profileId, $recipient) {
            $q->where('participant_1', $profileId)->where('participant_2', $recipient->id);
        })->orWhere(function ($q) use ($profileId, $recipient) {
            $q->where('participant_1', $recipient->id)->where('participant_2', $profileId);
        })->first();

        if ($existing) {
            $conversation = $this->sendMessageInternal($existing, $content, $profileId);
        } else {
            $conversation = Conversation::create([
                'participant_1' => min($profileId, (int) $recipient->id),
                'participant_2' => max($profileId, (int) $recipient->id),
                'order_id' => $orderId,
                'message_type' => 'direct',
                'last_message_at' => now(),
            ]);

            $this->sendMessageInternal($conversation, $content, $profileId);
        }

        $conversation = $conversation->fresh()->load(['participantOne', 'participantTwo', 'messages.sender']);

        // ── Notify the recipient ──────────────────────────────────
        $senderName = $this->profileName();
        $this->notificationManager->dispatch(
            (int) $recipient->id,
            'message.new',
            'New Message',
            "{$senderName} sent you a message.",
            [
                'conversation_id' => $conversation->id,
                'sender_id' => $profileId,
                'content_preview' => mb_substr($content, 0, 100),
            ]
        );

        // ── Send email to recipient ───────────────────────────────
        $this->sendNewMessageEmail((int) $recipient->id, $senderName, $content, (int) $conversation->id);

        return $conversation;
    }

    /**
     * Send a message in an existing conversation.
     */
    public function sendMessage(Conversation $conversation, string $content): Conversation
    {
        $profileId = (int) $this->profileId();

        $this->ensureParticipant($conversation);

        $conversation = $this->sendMessageInternal($conversation, $content, $profileId);
        $conversation = $conversation->fresh()->load(['participantOne', 'participantTwo', 'messages.sender']);

        // ── Notify the other participant ──────────────────────────
        $recipientId = (int) $conversation->participant_1 === $profileId
            ? (int) $conversation->participant_2
            : (int) $conversation->participant_1;

        $senderName = $this->profileName();
        $this->notificationManager->dispatch(
            $recipientId,
            'message.new',
            'New Message',
            "{$senderName} sent you a message.",
            [
                'conversation_id' => $conversation->id,
                'sender_id' => $profileId,
                'content_preview' => mb_substr($content, 0, 100),
            ]
        );

        // ── Send email to recipient ───────────────────────────────
        $this->sendNewMessageEmail($recipientId, $senderName, $content, (int) $conversation->id);

        return $conversation;
    }

    /**
     * Send a new-message email if the recipient has email notifications enabled.
     */
    protected function sendNewMessageEmail(int $recipientId, string $senderName, string $content, int $conversationId): void
    {
        $prefs = NotificationPreference::forUser($recipientId);
        if (! $prefs->isEmailEnabled('message.new')) {
            return;
        }

        $email = $this->emailService->getEmailForProfile($recipientId);
        $name = $this->emailService->getNameForProfile($recipientId);

        if ($email && $name) {
            $this->emailService->newMessage($email, $name, $senderName, $content, $conversationId);
        }
    }

    public function markAsRead(Conversation $conversation): void
    {
        $this->ensureParticipant($conversation);

        $profileId = (int) $this->profileId();

        Message::byConversation((int) $conversation->id)
            ->unread()
            ->where('sender_id', '!=', $profileId)
            ->update(['is_read' => true]);
    }

    public function unreadCount(): int
    {
        $profileId = (int) $this->profileId();

        return (int) Message::whereHas('conversation', function ($q) use ($profileId) {
            $q->forParticipant($profileId);
        })->unread()->where('sender_id', '!=', $profileId)->count();
    }

    protected function sendMessageInternal(Conversation $conversation, string $content, int $senderId): Conversation
    {
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $senderId,
            'content' => $content,
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $conversation;
    }

    protected function formatConversation(Conversation $conversation, int $profileId): array
    {
        $otherParticipant = (int) $conversation->participant_1 === $profileId
            ? $conversation->participantTwo
            : $conversation->participantOne;

        $otherParticipantData = null;
        if ($otherParticipant) {
            $otherUser = $otherParticipant->user;
            if ($otherUser && in_array($otherUser->role, ['admin', 'supervisor'])) {
                $otherParticipantData = [
                    'id' => 0,
                    'full_name' => 'Admin',
                    'username' => 'admin',
                    'avatar_url' => asset('img/default.png'),
                ];
            } else {
                $otherParticipantData = [
                    'id' => $otherParticipant->id,
                    'full_name' => $otherParticipant->full_name,
                    'username' => $otherParticipant->username,
                    'avatar_url' => ImageManager::fileUrl($otherParticipant->avatar_url),
                ];
            }
        }

        return [
            'id' => $conversation->id,
            'other_participant' => $otherParticipantData,
            'order_id' => $conversation->order_id,
            'last_message' => $conversation->latestMessage ? [
                'content' => $conversation->latestMessage->content,
                'sender_id' => $conversation->latestMessage->sender_id,
                'created_at' => $conversation->latestMessage->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => (int) $conversation->unread_count,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }

    protected function ensureParticipant(Conversation $conversation): void
    {
        $profileId = (int) $this->profileId();

        if ((int) $conversation->participant_1 !== $profileId && (int) $conversation->participant_2 !== $profileId) {
            abort(404);
        }
    }

    protected function profileName(): string
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        return $profile?->full_name ?? $user->email;
    }

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }
}
