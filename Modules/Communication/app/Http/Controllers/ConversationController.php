<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Http\Requests\AddGroupMembersRequest;
use Modules\Communication\Http\Requests\ListConversationMessagesRequest;
use Modules\Communication\Http\Requests\ListConversationsRequest;
use Modules\Communication\Http\Requests\PromoteGroupMembersRequest;
use Modules\Communication\Http\Requests\SearchConversationMessagesRequest;
use Modules\Communication\Http\Requests\SendGiftMessageRequest;
use Modules\Communication\Http\Requests\SendMessageRequest;
use Modules\Communication\Http\Requests\StartConversationRequest;
use Modules\Communication\Http\Requests\UpdateGroupSettingsRequest;
use Modules\Communication\Http\Resources\ConversationResource;
use Modules\Communication\Http\Resources\MessageResource;
use Modules\Communication\Models\ConversationParticipant;
use Modules\Communication\Services\GroupManagementService;
use Modules\Communication\Services\MessageService;
use Modules\Communication\Traits\ApiResponseTrait;
use Modules\Communication\Traits\FileManagementTrait;
use Throwable;

class ConversationController extends Controller
{
    use ApiResponseTrait, FileManagementTrait;

    public function __construct(
        private readonly MessageService $messageService,
        private readonly GroupManagementService $groupManagementService
    ) {}

    public function start(StartConversationRequest $request): JsonResponse
    {
        try {
            $result = $this->messageService->startConversation($request->user(), $request->validated());

            $statusCode = $result['created'] ? 201 : 200;
            $message = $result['created'] ? 'Conversation started successfully' : 'Conversation already exists';

            $data = [
                'conversation' => (new ConversationResource($result['conversation']))->toArray($request),
            ];

            return $this->sendResponse(
                $data,
                $message,
                $statusCode
            );
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to start conversation.', 500);
        }
    }

    public function index(ListConversationsRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->integer('per_page', 20);
            $search = trim((string) $request->input('query', ''));

            $conversations = $this->messageService->paginateConversations(
                (int) $request->user()->id,
                $perPage,
                $search !== '' ? $search : null,
            );

            $conversations = $conversations->through(
                fn ($conversation): array => (new ConversationResource($conversation))->toArray($request)
            );

            return $this->sendResponse($conversations, 'Conversations retrieved successfully');
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve conversations.', 500);
        }
    }

    public function newUsers(Request $request): JsonResponse
    {
        try {
            $authUserId = (int) ($request->user()?->id ?? 0);
            $blockedUserIds = $request->user()?->blocked_users ?? [];
            $searchTerm = trim((string) ($request->input('query', $request->input('search', ''))));
            $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
            $city = trim((string) ($request->input('city', '')));
            $country = trim((string) ($request->input('country', $request->input('country_name', $request->input('country_code', '')))));

            $learningLanguageFilters = $this->normaliseFilterValues(
                $this->firstPresentInput($request, ['learning_language', 'learning_preferences', 'learning_language_id', 'learning_preferences_id'])
            );

            $nativeLanguageFilters = $this->normaliseFilterValues(
                $this->firstPresentInput($request, ['native_language', 'native_languages', 'native_language_id', 'native_languages_id'])
            );

            $genderFilters = $this->normaliseGenderFilters(
                $this->normaliseFilterValues($this->firstPresentInput($request, ['gender', 'genders']))
            );

            [$minAge, $maxAge] = $this->resolveAgeRange($request);

            $chattedUserIds = ConversationParticipant::query()
                ->from('conversation_participants as cp_other')
                ->join('conversation_participants as cp_me', function ($join) use ($authUserId): void {
                    $join
                        ->on('cp_me.conversation_id', '=', 'cp_other.conversation_id')
                        ->where('cp_me.user_id', '=', $authUserId)
                        ->where('cp_me.status', '=', 'active')
                        ->whereNull('cp_me.deleted_from_list_at');
                })
                ->join('communication_conversations as conversations_table', 'conversations_table.id', '=', 'cp_other.conversation_id')
                ->where('conversations_table.is_group', false)
                ->where('cp_other.status', 'active')
                ->where('cp_other.user_id', '!=', $authUserId)
                ->pluck('cp_other.user_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $usersQuery = User::query()
                ->select(['id', 'name', 'avatar'])
                ->when($blockedUserIds !== [], function ($query) use ($blockedUserIds): void {
                    $query->whereNotIn('id', $blockedUserIds);
                })
                ->when($authUserId > 0, function ($query) use ($authUserId): void {
                    $query->where(function (Builder $blockedQuery) use ($authUserId): void {
                        $blockedQuery
                            ->whereJsonDoesntContain('blocked_users', $authUserId)
                            ->orWhereNull('blocked_users');
                    });
                })
                ->where('role', 'user')
                ->where('id', '!=', $authUserId)
                ->when($chattedUserIds !== [], function ($query) use ($chattedUserIds): void {
                    $query->whereNotIn('id', $chattedUserIds);
                })
                ->orderBy('name');

            if ($city !== '') {
                $usersQuery->where('city', 'LIKE', '%'.$city.'%');
            }

            if ($country !== '') {
                $countryLike = '%'.$country.'%';

                $usersQuery->where(function (Builder $query) use ($countryLike): void {
                    $query
                        ->where('country_name', 'LIKE', $countryLike)
                        ->orWhere('country_code', 'LIKE', $countryLike);
                });
            }

            if ($learningLanguageFilters !== []) {
                $this->applyOnboardingOptionFilter($usersQuery, ['learning_preferences'], $learningLanguageFilters);
            }

            if ($nativeLanguageFilters !== []) {
                $this->applyOnboardingOptionFilter($usersQuery, ['native_languages'], $nativeLanguageFilters);
            }

            if ($genderFilters !== []) {
                $this->applyGenderFilter($usersQuery, $genderFilters);
            }

            if ($minAge !== null || $maxAge !== null) {
                $this->applyAgeFilter($usersQuery, $minAge, $maxAge);
            }

            if ($searchTerm !== '') {
                $like = '%'.$searchTerm.'%';

                $usersQuery->where(function ($query) use ($like): void {
                    $query->where('name', 'LIKE', $like);
                });
            }

            $users = $usersQuery
                ->paginate($perPage)
                ->through(function (User $user): array {
                    $fullName = trim((string) ($user->name ?? ''));

                    return [
                        'id' => (int) $user->id,
                        'name' => $fullName !== '' ? $fullName : ('User '.(int) $user->id),
                        'avatar' => $user->avatar,
                        'is_online' => Cache::has('communication:user:online:'.(int) $user->id),
                    ];
                });

            return $this->sendResponse($users, 'New users retrieved successfully');
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve new users list.', 500);
        }
    }

    public function messages(ListConversationMessagesRequest $request, int $id): JsonResponse
    {
        try {
            $perPage = (int) $request->integer('per_page', 50);
            $lang = trim((string) $request->input('lang', ''));

            $messages = $this->messageService->paginateConversationMessages(
                $id,
                (int) $request->user()->id,
                $perPage,
                $lang !== '' ? $lang : null
            );

            $messages = $messages->through(
                fn ($message): array => (new MessageResource($message))->toArray($request)
            );

            return $this->sendResponse(
                [
                    'note' => ':::::::::Message fields: 
                      - status: sent | delivered | read (for current user)
                      - type: text (plain message) | attachment (message with file attachments) | system_call_event (for call related messages) | system_chat_event (for group invitation or update related messages)
                      -attachment: array of attachment details, type inside attachment are audio, video, image  ::::::::::',
                    'messages' => $messages->items(),
                    'meta' => [
                        'current_page' => $messages->currentPage(),
                        'last_page' => $messages->lastPage(),
                        'per_page' => $messages->perPage(),
                        'total' => $messages->total(),
                    ],
                ],
                'Messages retrieved successfully'
            );
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve messages.', 500);
        }
    }

    public function searchMessages(SearchConversationMessagesRequest $request, int $id): JsonResponse
    {
        try {
            $perPage = (int) $request->integer('per_page', 50);
            $query = trim((string) $request->input('query', $request->input('search', '')));

            $messages = $this->messageService->searchConversationMessages(
                $id,
                (int) $request->user()->id,
                $query,
                $perPage
            );

            $messages = $messages->through(
                fn ($message): array => (new MessageResource($message))->toArray($request)
            );

            return $this->sendResponse(
                [
                    'messages' => $messages->items(),
                    'meta' => [
                        'current_page' => $messages->currentPage(),
                        'last_page' => $messages->lastPage(),
                        'per_page' => $messages->perPage(),
                        'total' => $messages->total(),
                    ],
                ],
                'Messages retrieved successfully'
            );
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve messages.', 500);
        }
    }

    public function sendMessage(SendMessageRequest $request, int $id): JsonResponse
    {
        try {
            $message = $this->messageService->sendMessage(
                $request->user(),
                $id,
                $request->validated()
            );

            return $this->sendResponse(
                (new MessageResource($message))->toArray($request),
                'Message sent successfully',
                201
            );
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to send message.', 500);
        }
    }

    public function sendGift(SendGiftMessageRequest $request, int $id): JsonResponse
    {
        try {
            $message = $this->messageService->sendGiftMessage(
                $request->user(),
                $id,
                (int) $request->input('gift_id'),
                $request->input('content')
            );

            return $this->sendResponse(
                (new MessageResource($message))->toArray($request),
                'Gift sent successfully',
                201
            );
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to send gift.', 500);
        }
    }

    public function groupSettings(Request $request, int $id): JsonResponse
    {
        try {
            $data = $this->groupManagementService->getGroupSettings($request->user(), $id);

            return $this->sendResponse($data, 'Chat settings retrieved successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve chat settings.', 500);
        }
    }

    public function updateGroupSettings(UpdateGroupSettingsRequest $request, int $id): JsonResponse
    {
        try {
            $payload = $request->validated();

            $data = null;

            if (array_key_exists('name', $payload) && trim((string) ($payload['name'] ?? '')) !== '') {
                $data = $this->groupManagementService->updateGroupName(
                    $request->user(),
                    $id,
                    (string) ($payload['name'] ?? '')
                );
            }

            if ($request->hasFile('avatar')) {
                $uploadedAvatarPath = $this->uploadFile($request->file('avatar'), 'communication/conversations/avatars');

                if ($uploadedAvatarPath === null) {
                    throw ValidationException::withMessages([
                        'avatar' => ['Failed to upload avatar image.'],
                    ]);
                }

                $data = $this->groupManagementService->updateGroupAvatar(
                    $request->user(),
                    $id,
                    url($uploadedAvatarPath)
                );
            }

            if (array_key_exists('notification', $payload)) {
                $data = $this->groupManagementService->updateNotificationPreference(
                    $request->user(),
                    $id,
                    (bool) $payload['notification']
                );
            }

            if (array_key_exists('receive_audio_call', $payload)) {
                $data = $this->groupManagementService->updateAudioCallPreference(
                    $request->user(),
                    $id,
                    (bool) $payload['receive_audio_call']
                );
            }

            if (! is_array($data)) {
                throw ValidationException::withMessages([
                    'settings' => ['At least one setting is required.'],
                ]);
            }

            return $this->sendResponse($data, 'Chat settings retrieved successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to update chat settings.', 500);
        }
    }

    public function addGroupMembers(AddGroupMembersRequest $request, int $id): JsonResponse
    {
        try {
            $data = $this->groupManagementService->addMembers(
                $request->user(),
                $id,
                (array) $request->input('participant_ids', [])
            );

            return $this->sendResponse($data, 'Group members added successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to add members.', 500);
        }
    }

    public function removeGroupMembers(AddGroupMembersRequest $request, int $id): JsonResponse
    {
        try {
            $data = $this->groupManagementService->removeMembers(
                $request->user(),
                $id,
                (array) $request->input('participant_ids', [])
            );

            return $this->sendResponse($data, 'Group members removed successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to remove member.', 500);
        }
    }

    public function promoteGroupMembers(PromoteGroupMembersRequest $request, int $id): JsonResponse
    {
        try {
            $data = $this->groupManagementService->promoteMembersToAdmin(
                $request->user(),
                $id,
                (array) $request->input('participant_ids', [])
            );

            return $this->sendResponse($data, 'Group member promoted to admin successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to promote group members.', 500);
        }
    }

    public function clearGroupHistory(Request $request, int $id): JsonResponse
    {
        try {
            $data = $this->groupManagementService->clearChatHistory($request->user(), $id);

            return $this->sendResponse([], 'Chat history cleared successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to clear chat history.', 500);
        }
    }

    public function clearConversationHistory(Request $request, int $id): JsonResponse
    {
        try {
            $data = $this->groupManagementService->clearConversationHistory($request->user(), $id);

            return $this->sendResponse([], 'Chat history cleared successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to clear chat history from your side.', 500);
        }
    }

    public function leaveGroup(Request $request, int $id): JsonResponse
    {
        try {
            $data = $this->groupManagementService->leaveGroup($request->user(), $id);

            return $this->sendResponse(
                $data,
                'Left group successfully'
            );
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to leave group.', 500);
        }
    }

    public function deleteGroup(Request $request, int $id): JsonResponse
    {
        try {
            $this->groupManagementService->deleteGroup($request->user(), $id);

            return $this->sendResponse([], 'Group deleted successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to delete group.', 500);
        }
    }

    public function deleteConversationFromList(Request $request, int $id): JsonResponse
    {
        try {
            $data = $this->groupManagementService->deleteConversation($request->user(), $id);

            return $this->sendResponse([], 'Chat deleted successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Conversation not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to delete chat.', 500);
        }
    }

    private function firstPresentInput(Request $request, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function normaliseFilterValues(mixed $rawValue): array
    {
        if ($rawValue === null || $rawValue === '') {
            return [];
        }

        $values = is_array($rawValue) ? $rawValue : [$rawValue];

        return collect($values)
            ->flatMap(static function (mixed $value): array {
                if (is_array($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    $parts = preg_split('/[,|]/', $value);

                    return is_array($parts) ? $parts : [$value];
                }

                return [$value];
            })
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normaliseGenderFilters(array $filters): array
    {
        return collect($filters)
            ->map(static function (string $value): string {
                $normalised = strtolower($value);

                return match ($normalised) {
                    'm', 'man', 'boy', 'male' => 'male',
                    'f', 'woman', 'girl', 'female' => 'female',
                    'other', 'others', 'non-binary', 'nonbinary' => 'others',
                    default => $normalised,
                };
            })
            ->filter(static fn (string $value): bool => in_array($value, ['male', 'female', 'others'], true))
            ->unique()
            ->values()
            ->all();
    }

    private function applyGenderFilter(Builder $usersQuery, array $genderFilters): void
    {
        if ($genderFilters === []) {
            return;
        }

        if (Schema::hasColumn('users', 'gender')) {
            $usersQuery->whereIn('gender', $genderFilters);

            return;
        }

        if (Schema::hasTable('profiles') && Schema::hasColumn('profiles', 'gender')) {
            $usersQuery->whereExists(function ($profileQuery) use ($genderFilters): void {
                $profileQuery
                    ->selectRaw('1')
                    ->from('profiles')
                    ->whereColumn('profiles.user_id', 'users.id')
                    ->whereIn('profiles.gender', $genderFilters);
            });

            return;
        }

        $this->applyOnboardingOptionFilter($usersQuery, ['gender', 'genders'], $genderFilters);
    }

    private function resolveAgeRange(Request $request): array
    {
        $minAge = $this->extractAgeValue($request, ['min_age', 'age_min', 'age_from', 'from_age']);
        $maxAge = $this->extractAgeValue($request, ['max_age', 'age_max', 'age_to', 'to_age']);

        $exactAge = $this->extractAgeValue($request, ['age']);
        if ($exactAge !== null && $minAge === null && $maxAge === null) {
            $minAge = $exactAge;
            $maxAge = $exactAge;
        }

        $ageRange = $request->input('age_range');
        if (is_array($ageRange)) {
            $rangeValues = array_values($ageRange);

            if ($minAge === null && isset($rangeValues[0]) && is_numeric($rangeValues[0])) {
                $minAge = (int) $rangeValues[0];
            }

            if ($maxAge === null && isset($rangeValues[1]) && is_numeric($rangeValues[1])) {
                $maxAge = (int) $rangeValues[1];
            }
        } elseif (is_string($ageRange)) {
            $normalised = trim($ageRange);

            if (preg_match('/^(\d+)\s*-\s*(\d+)(\+)?$/', $normalised, $matches) === 1) {
                $minAge ??= (int) $matches[1];
                $maxAge ??= (int) $matches[2];

                if (($matches[3] ?? '') === '+') {
                    $maxAge = null;
                }
            } elseif (preg_match('/^(\d+)\+$/', $normalised, $matches) === 1) {
                $minAge ??= (int) $matches[1];
            }
        }

        if ($minAge !== null) {
            $minAge = $this->normaliseAgeValue($minAge);
        }

        if ($maxAge !== null) {
            $maxAge = $this->normaliseAgeValue($maxAge);
        }

        if ($minAge !== null && $maxAge !== null && $minAge > $maxAge) {
            [$minAge, $maxAge] = [$maxAge, $minAge];
        }

        return [$minAge, $maxAge];
    }

    private function extractAgeValue(Request $request, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            if ($value === null || $value === '' || ! is_numeric($value)) {
                continue;
            }

            return (int) $value;
        }

        return null;
    }

    private function normaliseAgeValue(int $age): int
    {
        return max(0, min(150, $age));
    }

    private function applyAgeFilter(Builder $usersQuery, ?int $minAge, ?int $maxAge): void
    {
        // Calculate birthday bounds so age comparisons are inclusive and correct.
        // Users with age >= $minAge were born on or before the date `now - $minAge years`.
        // Users with age <= $maxAge were born on or after the date `now - $maxAge years`.
        $maxBirthday = $minAge !== null ? now()->subYears($minAge)->toDateString() : null;
        $minBirthday = $maxAge !== null ? now()->subYears($maxAge)->toDateString() : null;

        $usersQuery->where(function (Builder $ageQuery) use ($minAge, $maxAge, $minBirthday, $maxBirthday): void {
            $ageQuery
                ->where(function (Builder $birthdayQuery) use ($minBirthday, $maxBirthday): void {
                    $birthdayQuery->whereNotNull('birthday');

                    if ($maxBirthday !== null) {
                        $birthdayQuery->whereDate('birthday', '<=', $maxBirthday);
                    }

                    if ($minBirthday !== null) {
                        $birthdayQuery->whereDate('birthday', '>=', $minBirthday);
                    }
                })
                ->orWhere(function (Builder $givenAgeQuery) use ($minAge, $maxAge): void {
                    $givenAgeQuery
                        ->whereNull('birthday')
                        ->whereNotNull('given_age');

                    if ($minAge !== null) {
                        $givenAgeQuery->where('given_age', '>=', $minAge);
                    }

                    if ($maxAge !== null) {
                        $givenAgeQuery->where('given_age', '<=', $maxAge);
                    }
                });
        });
    }
}
