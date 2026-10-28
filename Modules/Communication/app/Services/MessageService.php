<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Models\CoinTransaction;
use App\Models\CoinWallet;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Events\ConversationUpdated;
use Modules\Communication\Events\MessageSent;
use Modules\Communication\Http\Resources\ConversationResource;
use Modules\Communication\Models\Conversation;
use Modules\Communication\Models\ConversationParticipant;
use Modules\Communication\Models\Gift;
use Modules\Communication\Models\GiftSend;
use Modules\Communication\Models\Message;
use Modules\Communication\Models\MessageUserDetail;
use Modules\Communication\Repositories\Contracts\MessageRepositoryInterface;
use Modules\Communication\Traits\FileManagementTrait;
use Modules\Communication\Traits\UniqueIdentifierTrait;

class MessageService
{
    use FileManagementTrait;
    use UniqueIdentifierTrait;

    public function __construct(
        private readonly MessageRepositoryInterface $messages,
        private readonly GiftService $giftService,
        private readonly GroupAvatarService $groupAvatarService
    ) {}

    public function paginateConversations(int $userId, int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        return $this->messages->paginateConversationsForUser($userId, $perPage, $search);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{conversation: Conversation, created: bool, message: ?Message}
     */
    public function startConversation(User $initiator, array $payload): array
    {
        $authUserId = (int) $initiator->id;
        $conversationId = (int) ($payload['conversation_id'] ?? 0);
        $created = false;
        $conversationType = $conversationId > 0 ? null : (string) ($payload['type'] ?? 'private');
        $invitedParticipantIds = [];
        $groupAvatarParticipantIds = [];
        $shouldGenerateGroupAvatar = false;

        if ($conversationId > 0) {
            $this->assertActiveParticipant($conversationId, $authUserId);
        } else {
            $type = $conversationType;

            $participantIds = collect($payload['participant_ids'] ?? [])
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->reject(fn (int $id): bool => $id === $authUserId)
                ->unique()
                ->values()
                ->all();

            $conversationId = DB::transaction(function () use ($authUserId, $initiator, $payload, $type, $participantIds, &$created, &$invitedParticipantIds, &$groupAvatarParticipantIds, &$shouldGenerateGroupAvatar): int {
                if ($type === 'private') {
                    $targetUserId = (int) ($participantIds[0] ?? 0);

                    if ($targetUserId <= 0) {
                        throw ValidationException::withMessages([
                            'participant_ids' => ['Private chat requires exactly one valid participant id.'],
                        ]);
                    }

                    $this->assertPrivateChatNotBlocked($initiator, $targetUserId);

                    $existingConversation = $this->findExistingPrivateConversation($authUserId, $targetUserId);

                    if ($existingConversation !== null) {
                        $created = false;

                        return (int) $existingConversation->id;
                    }

                    $conversation = Conversation::query()->create([
                        'is_group' => false,
                        'name' => null,
                        'avatar' => null,
                    ]);

                    $this->createParticipants((int) $conversation->id, [$authUserId, $targetUserId], $authUserId);
                    $created = true;

                    return (int) $conversation->id;
                }

                if ($type === 'group') {
                    $groupAvatar = trim((string) ($payload['avatar'] ?? ''));

                    $groupName = trim((string) ($payload['name'] ?? ''));

                    if ($groupName === '') {
                        $participantNames = User::query()
                            ->whereIn('id', $participantIds)
                            ->pluck('name')
                            ->filter()
                            ->values()
                            ->all();

                        $groupName = implode(', ', array_slice($participantNames, 0, 3));
                        $groupName = $groupName !== '' ? $groupName : 'Group Chat';
                    }

                    $conversation = Conversation::query()->create([
                        'is_group' => true,
                        'name' => $groupName,
                        'avatar' => $groupAvatar !== '' ? $groupAvatar : null,
                    ]);

                    $allParticipantIds = collect(array_merge([$authUserId], $participantIds))
                        ->unique()
                        ->values()
                        ->all();

                    $this->createParticipants((int) $conversation->id, $allParticipantIds, $authUserId);

                    if ($groupAvatar === '') {
                        $groupAvatarParticipantIds = $allParticipantIds;
                        $shouldGenerateGroupAvatar = true;
                    }

                    $invitedParticipantIds = $participantIds;
                    $created = true;

                    return (int) $conversation->id;
                }

                throw ValidationException::withMessages([
                    'type' => ['The selected type is invalid.'],
                ]);
            });
        }

        $messageText = trim((string) ($payload['message'] ?? ''));
        $message = null;
        $messageType = (string) ($payload['message_type'] ?? 'text');

        if ($created && $conversationType === 'group' && $shouldGenerateGroupAvatar && $groupAvatarParticipantIds !== []) {
            $avatarUrl = $this->groupAvatarService->generateConversationAvatar($conversationId, $groupAvatarParticipantIds);

            if ($avatarUrl !== null) {
                Conversation::query()->whereKey($conversationId)->update(['avatar' => $avatarUrl]);
            }
        }

        if ($created && $conversationType === 'group' && $invitedParticipantIds !== []) {
            $message = $this->sendGroupInviteSystemMessage($initiator, $conversationId, $invitedParticipantIds);
        }

        // if type wave then send wave message
        if ($messageType === 'wave') {
            $message = $this->sendWaveMessage($initiator, $conversationId);
        } elseif ($messageText !== '') {
            $message = $this->sendMessage($initiator, $conversationId, [
                'type' => $messageType,
                'content' => $messageText,
            ]);
        }

        $conversation = $this->loadConversationForResponse($conversationId, $authUserId);

        return [
            'conversation' => $conversation,
            'created' => $created,
            'message' => $message,
        ];
    }

    public function sendWaveMessage(User $sender, int $conversationId): Message
    {
        return $this->sendMessage($sender, $conversationId, [
            'type' => 'wave',
        ]);
    }

    public function paginateConversationMessages(int $conversationId, int $userId, int $perPage = 50, ?string $lang = null): LengthAwarePaginator
    {
        $user = User::query()->findOrFail($userId);
        $this->assertActiveParticipant($conversationId, $userId);
        $this->assertConversationNotBlocked($user, $conversationId);

        $paginator = $this->messages->paginateMessages(
            $conversationId,
            $userId,
            $perPage,
            $this->blockedUserIds($user)
        );

        $this->markReceipts(
            collect($paginator->items())->filter(fn ($item): bool => $item instanceof Message),
            $userId
        );

        if ($lang !== null) {
            // $this->translateMessagesWithNLLB($paginator->items(), $lang);
        }

        return $paginator;
    }

    /**
     * Maps standard language codes to NLLB FLORES-200 codes.
     */
    private function getNllbLangCode(string $lang): string
    {
        $lang = mb_strtolower(trim($lang));
        $map = [
            'english' => 'eng_Latn',
            'bengali' => 'ben_Beng',
            'bangla' => 'ben_Beng',
            'arabic' => 'arb_Arab',
            'somali' => 'som_Latn',
            'french' => 'fra_Latn',
            'spanish' => 'spa_Latn',
            'hindi' => 'hin_Deva',
            'urdu' => 'urd_Arab',
            'chinese' => 'zho_Hans',
            'russian' => 'rus_Cyrl',
            'german' => 'deu_Latn',
            'italian' => 'ita_Latn',
            'portuguese' => 'por_Latn',
            'japanese' => 'jpn_Jpan',
            'korean' => 'kor_Hang',
            'turkish' => 'tur_Latn',
            'indonesian' => 'ind_Latn',
            'malay' => 'zsm_Latn',
            'thai' => 'tha_Thai',
            'vietnamese' => 'vie_Latn',
            'dutch' => 'nld_Latn',
            'polish' => 'pol_Latn',
            'ukrainian' => 'ukr_Cyrl',
            'persian' => 'pes_Arab',
            'farsi' => 'pes_Arab',
            'tamil' => 'tam_Taml',
            // Add more mappings here if needed
        ];

        return $map[$lang] ?? 'eng_Latn'; // Default fallback
    }

    /**
     * Translates using NLLB-200 via custom Docker API to ensure accurate and fast translation.
     */
    private function translateMessagesWithNLLB(array $items, string $targetLang): void
    {
        $messages = collect($items)
            ->filter(fn ($item): bool => $item instanceof Message)
            ->values();

        if ($messages->isEmpty()) {
            return;
        }

        $untranslatedMessages = [];
        $textsToTranslate = [];

        // 1. Check Cache
        foreach ($messages as $message) {
            $cacheKey = "msg_translation_{$message->id}_{$targetLang}";
            $cachedTranslation = Cache::get($cacheKey);

            if ($cachedTranslation) {
                $message->content = $cachedTranslation;
                $message->is_translated = true;
            } else {
                $untranslatedMessages[] = $message;
                $textsToTranslate[] = (string) $message->content;
            }
        }

        if (empty($textsToTranslate)) {
            return;
        }

        // 2. Call NLLB API
        try {
            $response = Http::withBasicAuth('username', 'password')
                ->timeout(120)
                ->post('endpointname', [
                    'texts' => $textsToTranslate,
                    'source_lang' => 'eng_Latn', // Assuming the base language of the app is English
                    'target_lang' => $this->getNllbLangCode($targetLang),
                ]);

            if (! $response->successful()) {
                Log::error('NLLB translation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return;
            }

            // Extract the 'translations' array directly from the response
            $translations = $response->json('translations');

            if (
                ! is_array($translations) ||
                count($translations) !== count($untranslatedMessages)
            ) {
                Log::warning('Invalid translation response', [
                    'response' => $response->json(),
                ]);

                return;
            }

            // 3. Apply translations and save to cache
            foreach ($untranslatedMessages as $index => $message) {
                $translatedText = (string) $translations[$index];

                $message->content = $translatedText;
                $message->is_translated = true;

                Cache::put(
                    "msg_translation_{$message->id}_{$targetLang}",
                    $translatedText,
                    now()->addDays(7)
                );
            }
        } catch (\Throwable $e) {
            Log::error('NLLB request failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function searchConversationMessages(int $conversationId, int $userId, string $query, int $perPage = 50): LengthAwarePaginator
    {
        $user = User::query()->findOrFail($userId);
        $this->assertActiveParticipant($conversationId, $userId);
        $this->assertConversationNotBlocked($user, $conversationId);

        $search = trim($query);
        if ($search === '') {
            throw ValidationException::withMessages([
                'query' => ['Search query is required.'],
            ]);
        }

        $paginator = $this->messages->searchMessages(
            $conversationId,
            $userId,
            $search,
            $perPage,
            $this->blockedUserIds($user)
        );

        $this->markReceipts(
            collect($paginator->items())->filter(fn ($item): bool => $item instanceof Message),
            $userId
        );

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendMessage(User $sender, int $conversationId, array $payload): Message
    {
        $this->assertActiveParticipant($conversationId, (int) $sender->id);
        $this->assertConversationNotBlocked($sender, $conversationId);
        $this->restoreConversationInListForUser($conversationId, (int) $sender->id);

        $attachments = $this->prepareAttachments($payload['attachments'] ?? []);
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        $type = (string) ($payload['type'] ?? 'text');
        if ($attachments !== [] && $type === 'text') {
            $type = 'attachment';
        }

        $content = isset($payload['content']) ? (string) $payload['content'] : null;

        if ($type === 'gift') {
            $giftId = (int) ($payload['gift_id'] ?? 0);
            $metadata = $this->giftService->attachGiftMetadata($giftId, $metadata);
            $content ??= 'Sent a gift!';
        }

        $message = $this->messages->create([
            'id' => $this->generateUniqueId('msg_'),
            'conversation_id' => $conversationId,
            'sender_id' => (int) $sender->id,
            'type' => $type,
            'content' => $content,
            'attachments' => $attachments !== [] ? $attachments : null,
            'metadata' => $metadata !== [] ? $metadata : null,
            'status' => 'sent',
            'reactions_summary' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // $participantIds = $this->messages->getParticipantIds($conversationId, (int) $sender->id);

        $participantIds = $this->messages->getParticipantIds($conversationId);

        if ((string) config('communication.receipt_tracking', 'detailed') !== 'none') {
            $this->messages->upsertMessageDetails((string) $message->id, $participantIds);
        }

        $freshMessage = $this->messages->findMessage((string) $message->id) ?? $message;

        event(new MessageSent(
            $conversationId,
            $participantIds,
            $this->serializeMessageForBroadcast($freshMessage)
        ));

        $allParticipantIds = $this->messages->getParticipantIds($conversationId);

        foreach ($allParticipantIds as $participantId) {
            event(new ConversationUpdated(
                $conversationId,
                [$participantId],
                $this->serializeConversationForBroadcast($conversationId, $participantId)
            ));
        }

        return $freshMessage;
    }

    public function sendGiftMessage(User $sender, int $conversationId, int $giftId, ?string $content = null): Message
    {
        // Create the message
        $message = $this->sendMessage($sender, $conversationId, [
            'type' => 'gift',
            'gift_id' => $giftId,
            'content' => $content,
            'metadata' => [],
            'attachments' => [],
        ]);

        // Also create a GiftSend record for consistency with receivedGifts endpoint
        try {
            // Get the gift
            $gift = Gift::query()->where('id', $giftId)->where('is_active', true)->firstOrFail();

            // Get the conversation to find the receiver(s)
            $conversation = Conversation::findOrFail($conversationId);

            // Get all other participants (works for one-on-one and group chats)
            $receiverIds = $conversation->participants()
                ->where('user_id', '!=', $sender->id)
                ->distinct('user_id')
                ->pluck('user_id')
                ->toArray();

            Log::info('Gift send attempt', [
                'sender_id' => $sender->id,
                'gift_id' => $giftId,
                'conversation_id' => $conversationId,
                'receiver_ids' => $receiverIds,
            ]);

            if (count($receiverIds) > 0) {
                $totalCost = $gift->coin_cost;
                $wallet = CoinWallet::getOrCreate((int) $sender->id);

                Log::info('Wallet check', [
                    'sender_id' => $sender->id,
                    'required' => $totalCost,
                    'balance' => $wallet->sm_coin_balance,
                ]);

                // Only create GiftSend if sender has sufficient coins
                if ($wallet->sm_coin_balance >= $totalCost) {
                    DB::transaction(function () use ($sender, $receiverIds, $gift, $totalCost) {
                        $wallet = CoinWallet::getOrCreate((int) $sender->id);
                        $wallet->debitCoins($totalCost);

                        // Create GiftSend for each receiver (supports group chats)
                        foreach ($receiverIds as $receiverId) {
                            GiftSend::create([
                                'sender_id' => (int) $sender->id,
                                'receiver_id' => (int) $receiverId,
                                'gift_id' => $gift->id,
                                'quantity' => 1,
                                'coin_cost' => $totalCost,
                            ]);
                        }

                        CoinTransaction::create([
                            'user_id' => (int) $sender->id,
                            'type' => 'gift_send',
                            'sm_coin' => -$totalCost,
                            'usd_amount' => 0,
                            'reference_id' => null,
                            'meta' => [
                                'gift_id' => $gift->id,
                                'gift_name' => $gift->title,
                                'receiver_count' => count($receiverIds),
                                'quantity' => 1,
                            ],
                            'status' => 'completed',
                        ]);

                        Log::info('Gift sent successfully', [
                            'sender_id' => $sender->id,
                            'receiver_ids' => $receiverIds,
                            'gift_id' => $gift->id,
                        ]);
                    });
                } else {
                    Log::warning('Insufficient coins to send gift', [
                        'sender_id' => $sender->id,
                        'required' => $totalCost,
                        'balance' => $wallet->sm_coin_balance,
                    ]);
                }
            } else {
                Log::warning('No receivers found in conversation', [
                    'sender_id' => $sender->id,
                    'conversation_id' => $conversationId,
                ]);
            }
        } catch (\Exception $e) {
            // Log but don't fail message sending if GiftSend creation fails
            Log::error('Failed to create GiftSend record: '.$e->getMessage(), [
                'sender_id' => $sender->id,
                'gift_id' => $giftId,
                'conversation_id' => $conversationId,
                'exception' => (string) $e,
            ]);
        }

        return $message;
    }

    public function resolveReactionsSummary(string $messageId, int $authUserId): array
    {
        $summary = $this->messages->aggregateReactions($messageId, $authUserId);
        $this->messages->update($messageId, ['reactions_summary' => $summary]);

        return $summary;
    }

    public function addReaction(string $messageId, int $userId, ?string $reaction): Message
    {
        $message = $this->messages->findMessage($messageId);

        if ($message === null) {
            throw (new ModelNotFoundException)->setModel(Message::class, [$messageId]);
        }

        $this->assertActiveParticipant((int) $message->conversation_id, $userId);
        $this->messages->upsertReaction($messageId, $userId, $reaction);

        $summary = $this->resolveReactionsSummary($messageId, $userId);

        $message->reactions_summary = $summary;

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessageInfo(string $messageId): array
    {
        $message = $this->messages->findMessage($messageId, true);

        if ($message === null) {
            throw (new ModelNotFoundException)->setModel(Message::class, [$messageId]);
        }

        $details = $message->userDetails;

        $readBy = [];
        $deliveredTo = [];

        foreach ($details as $detail) {
            $name = $this->userDisplayName($detail->user);

            if ($detail->read_at !== null) {
                $readBy[] = [
                    'user_id' => (int) $detail->user_id,
                    'name' => $name,
                    'avatar' => $detail->user?->avatar,
                    'read_at' => $detail->read_at?->toISOString(),
                ];
            }

            if ($detail->delivered_at !== null) {
                $deliveredTo[] = [
                    'user_id' => (int) $detail->user_id,
                    'name' => $name,
                    'avatar' => $detail->user?->avatar,
                    'delivered_at' => $detail->delivered_at?->toISOString(),
                ];
            }
        }

        return [
            'message_id' => (string) $message->id,
            'read_by' => $readBy,
            'delivered_to' => $deliveredTo,
        ];
    }

    private function assertActiveParticipant(int $conversationId, int $userId): void
    {
        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();

        if (! $isParticipant) {
            throw new AuthorizationException('Conversation not found.');
        }
    }

    private function assertPrivateChatNotBlocked(User $initiator, int $targetUserId): void
    {
        $targetUser = User::query()->find($targetUserId);

        if (! $targetUser instanceof User) {
            throw ValidationException::withMessages([
                'participant_ids' => ['Private chat requires exactly one valid participant id.'],
            ]);
        }

        if ($initiator->hasBlocked($targetUserId)) {
            throw new AuthorizationException('You have blocked this user.');
        }

        if ($targetUser->hasBlocked((int) $initiator->id)) {
            throw new AuthorizationException('This user has blocked you.');
        }
    }

    private function assertConversationNotBlocked(User $sender, int $conversationId): void
    {
        $conversation = Conversation::query()
            ->with(['participants' => function ($query): void {
                $query->where('status', 'active')->with('user');
            }])
            ->findOrFail($conversationId);

        if ((bool) $conversation->is_group) {
            return;
        }

        $otherParticipant = $conversation->participants->first(fn ($participant) => (int) $participant->user_id !== (int) $sender->id);

        if (! $otherParticipant instanceof ConversationParticipant || ! $otherParticipant->user instanceof User) {
            return;
        }

        if ($sender->hasBlocked((int) $otherParticipant->user->id)) {
            throw new AuthorizationException('You have blocked this user.');
        }

        if ($otherParticipant->user->hasBlocked((int) $sender->id)) {
            throw new AuthorizationException('You are not allowed to do this action.');
        }
    }

    private function restoreConversationInListForUser(int $conversationId, int $userId): void
    {
        ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNotNull('deleted_from_list_at')
            ->update([
                'deleted_from_list_at' => null,
            ]);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    private function prepareAttachments(array $files): array
    {
        $attachments = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $mimeType = (string) ($file->getMimeType() ?? 'application/octet-stream');
            $attachments[] = [
                'type' => $this->mapAttachmentType($mimeType),
                'url' => $this->uploadFile($file, 'communication/attachments'),
                'mime_type' => $mimeType,
                'file_size' => (int) $file->getSize(),
                'file_name' => (string) $file->getClientOriginalName(),
            ];
        }

        return $attachments;
    }

    private function mapAttachmentType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        return 'file';
    }

    private function markReceipts(Collection $messages, int $userId): void
    {
        $trackingMode = (string) config('communication.receipt_tracking', 'detailed');

        if ($trackingMode === 'none' || $messages->isEmpty()) {
            return;
        }

        foreach ($messages as $message) {
            if ((int) $message->sender_id === $userId) {
                continue;
            }

            $detail = MessageUserDetail::query()->firstOrNew([
                'message_id' => (string) $message->id,
                'user_id' => $userId,
            ]);

            $detail->delivered_at ??= now();

            if ($trackingMode === 'detailed') {
                $detail->read_at ??= now();
            }

            $detail->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessageForBroadcast(Message $message): array
    {
        return [
            'id' => (string) $message->id,
            'sender_id' => (int) $message->sender_id,
            'type' => (string) $message->type,
            'content' => $message->content,
            'attachments' => $message->attachments,
            'metadata' => $message->metadata,
            'status' => (string) $message->status,
            'reactions_summary' => $message->reactions_summary ?? [],
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversationForBroadcast(int $conversationId, int $viewerId): array
    {
        $viewer = User::query()->find($viewerId);

        if (! $viewer instanceof User) {
            return [
                'conversation_id' => $conversationId,
            ];
        }

        try {
            $conversation = $this->loadConversationForResponse($conversationId, $viewerId);
        } catch (ModelNotFoundException) {
            return [
                'conversation_id' => $conversationId,
            ];
        }

        $request = Request::create('/api/communication/conversations/'.$conversationId, 'GET');
        $request->setUserResolver(static fn (): User => $viewer);

        return (new ConversationResource($conversation))->toArray($request);
    }

    private function blockedUserIds(User $user): array
    {
        $blockedUsers = $user->blocked_users ?? [];

        if (! is_array($blockedUsers)) {
            return [];
        }

        return array_map('intval', array_values($blockedUsers));
    }

    private function userDisplayName(?User $user): string
    {
        if ($user === null) {
            return 'Unknown';
        }

        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return 'User '.(int) $user->id;
    }

    /**
     * Create a system message when a call is initiated
     *
     * @param  array<string, mixed>  $callData
     */
    public function createCallInitiatedMessage(User $initiator, int $conversationId, array $callData): Message
    {
        $initiatorName = $this->userDisplayName($initiator);
        $callType = (string) ($callData['call_type'] ?? 'audio');
        $content = sprintf('%s started a %s call', $initiatorName, $callType);

        return $this->sendMessage($initiator, $conversationId, [
            'type' => 'system_call_event',
            'content' => $content,
            'attachments' => [],
        ]);
    }

    /**
     * Create a system message when a call ends
     *
     * @param  array<string, mixed>  $callData
     */
    public function createCallEndedMessage(User $endedBy, int $conversationId, array $callData): Message
    {
        $initiatorName = $callData['initiator_name'] ?? 'User';
        $callerName = $this->userDisplayName($endedBy);
        $callType = (string) ($callData['call_type'] ?? 'audio');
        $duration = (int) ($callData['duration_seconds'] ?? 0);
        $status = (string) ($callData['status'] ?? 'completed');
        $hadOtherParticipants = (bool) ($callData['had_other_participants'] ?? true);

        // Format duration nicely
        $durationStr = $this->formatCallDuration($duration);

        if ($status === 'missed') {
            $article = $this->indefiniteArticle($callType);
            $content = sprintf('%s missed %s %s call', $callerName, $article, $callType);
        } elseif (! $hadOtherParticipants) {
            $content = sprintf('%s cancelled the call', $callerName);
        } else {
            $content = sprintf('%s ended the call with %s (%s)', $callerName, $initiatorName, $durationStr);
        }

        return $this->sendMessage($endedBy, $conversationId, [
            'type' => 'system_call_event',
            'content' => $content,
            'attachments' => [],
        ]);
    }

    /**
     * Format call duration in human-readable format
     */
    private function formatCallDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0
                ? sprintf('%dm %ds', $minutes, $remainingSeconds)
                : sprintf('%dm', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0
            ? sprintf('%dh %dm', $hours, $remainingMinutes)
            : sprintf('%dh', $hours);
    }

    private function indefiniteArticle(string $word): string
    {
        $firstChar = strtolower(substr($word, 0, 1));

        return in_array($firstChar, ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }

    /**
     * @param  array<int>  $invitedUserIds
     */
    private function sendGroupInviteSystemMessage(User $initiator, int $conversationId, array $invitedUserIds): ?Message
    {
        $invitedUsers = User::query()
            ->whereIn('id', $invitedUserIds)
            ->get(['id', 'name', 'avatar'])
            ->keyBy('id');

        $invitees = [];
        foreach ($invitedUserIds as $invitedUserId) {
            $invitedUser = $invitedUsers->get($invitedUserId);

            if (! $invitedUser instanceof User) {
                continue;
            }

            $invitees[] = [
                'id' => (int) $invitedUserId,
                'name' => $this->userDisplayName($invitedUser),
                'avatar' => $invitedUser->avatar,
            ];
        }

        if ($invitees === []) {
            return null;
        }

        $inviterName = $this->userDisplayName($initiator);
        $inviteeNames = collect($invitees)->pluck('name')->all();
        $content = sprintf('%s invited %s to Group chat', $inviterName, implode(', ', $inviteeNames));

        return $this->sendMessage($initiator, $conversationId, [
            'type' => 'system_chat_event',
            'content' => $content,
            'metadata' => [
                'event' => 'participants_invited',
                'inviter' => [
                    'id' => (int) $initiator->id,
                    'name' => $inviterName,
                    'avatar' => $initiator->avatar,
                ],
                'invitees' => $invitees,
            ],
            'attachments' => [],
        ]);
    }

    private function findExistingPrivateConversation(int $authUserId, int $targetUserId): ?Conversation
    {
        return Conversation::query()
            ->where('is_group', false)
            ->whereHas('participants', function ($query) use ($authUserId): void {
                $query->where('status', 'active')->where('user_id', $authUserId);
            })
            ->whereHas('participants', function ($query) use ($targetUserId): void {
                $query->where('status', 'active')->where('user_id', $targetUserId);
            })
            ->whereDoesntHave('participants', function ($query) use ($authUserId, $targetUserId): void {
                $query
                    ->where('status', 'active')
                    ->whereNotIn('user_id', [$authUserId, $targetUserId]);
            })
            ->latest('updated_at')
            ->first();
    }

    /**
     * @param  array<int>  $participantIds
     */
    private function createParticipants(int $conversationId, array $participantIds, int $initiatorId): void
    {
        $now = now();

        $rows = [];
        foreach ($participantIds as $participantId) {
            $rows[] = [
                'conversation_id' => $conversationId,
                'user_id' => $participantId,
                'role' => $participantId === $initiatorId ? 'admin' : 'member',
                'status' => 'active',
                'notification_enabled' => true,
                'receive_audio_call_enabled' => true,
                'joined_at' => $now,
            ];
        }

        ConversationParticipant::query()->insert($rows);
    }

    private function loadConversationForResponse(int $conversationId, int $authUserId): Conversation
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->with([
                'participants' => function ($query): void {
                    $query
                        ->where('status', 'active')
                        ->select([
                            'id',
                            'conversation_id',
                            'user_id',
                            'role',
                            'status',
                            'notification_enabled',
                            'receive_audio_call_enabled',
                            'history_cleared_message_id',
                            'last_msg_delivery_id',
                            'last_msg_delivery_at',
                            'last_msg_read_id',
                            'last_msg_read_at',
                            'joined_at',
                            'left_at',
                        ]);
                },
                'participants.user' => function ($query) {
                    $query->select(['id', 'name', 'avatar']);
                },
                'latestMessage' => function ($query) use ($authUserId): void {
                    $query->whereRaw(
                        "communication_messages.id > COALESCE((SELECT cp.history_cleared_message_id FROM conversation_participants cp WHERE cp.conversation_id = communication_messages.conversation_id AND cp.user_id = ? AND cp.status = 'active' LIMIT 1), '')",
                        [$authUserId]
                    );
                },
                'latestMessage.sender:id,name,avatar',
            ])
            ->withCount([
                'participants as total_participants' => function ($query): void {
                    $query->where('status', 'active');
                },
                'messages as unread_count' => function ($query) use ($authUserId): void {
                    $query
                        ->whereRaw(
                            "communication_messages.id > COALESCE((SELECT cp.history_cleared_message_id FROM conversation_participants cp WHERE cp.conversation_id = communication_messages.conversation_id AND cp.user_id = ? AND cp.status = 'active' LIMIT 1), '')",
                            [$authUserId]
                        )
                        ->where('sender_id', '!=', $authUserId)
                        ->whereDoesntHave('userDetails', function ($receiptQuery) use ($authUserId): void {
                            $receiptQuery->where('user_id', $authUserId)->whereNotNull('read_at');
                        });
                },
            ])
            ->firstOrFail();
    }
}
