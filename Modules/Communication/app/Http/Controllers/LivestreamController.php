<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Models\Gift;
use Modules\Communication\Models\LivestreamGamificationPreset;
use Modules\Communication\Services\CallService;
use Modules\Communication\Traits\ApiResponseTrait;
use Modules\Communication\Traits\FileManagementTrait;
use Throwable;

class LivestreamController extends Controller
{
    use ApiResponseTrait, FileManagementTrait;

    public function __construct(private readonly CallService $callService) {}

    public function start(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:1000',
                'thumbnail' => 'nullable|file',
                'stream_layout' => 'nullable|string|in:solo,duo,group,party,stage',
                'allow_gift_bet' => 'nullable|boolean',
                'custom_questions' => 'nullable|array',
                'conversation_id' => 'nullable|integer',
                'background_image' => 'nullable|file',
            ]);

            $thumbnailUrl = $request->input('thumbnail_url');
            if ($request->hasFile('thumbnail_url')) {
                $thumbnailUrl = url($this->uploadFile($request->file('thumbnail_url'), 'communication/livestreams/thumbnails'));
            }

            $backgroundImageUrl = $request->input('background_image_url');
            if ($request->hasFile('background_image')) {
                $backgroundImageUrl = $this->uploadFile($request->file('background_image'), 'communication/livestreams/backgrounds', 's3');
            }

            $payload = $this->callService->startStream(
                host: $request->user(),
                title: $request->input('title'),
                description: $request->input('description'),
                thumbnailUrl: $thumbnailUrl,
                backgroundImageUrl: $backgroundImageUrl,
                streamLayout: $request->input('stream_layout', 'solo'),
                allowGiftBet: $request->boolean('allow_gift_bet'),
                customQuestions: $request->input('custom_questions'),
                conversationId: $request->filled('conversation_id') ? (int) $request->input('conversation_id') : null,
            );

            return $this->sendResponse($payload, 'Livestream started', 201);
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to start livestream.', 500);
        }
    }

    public function join(Request $request, int|string $id): JsonResponse
    {
        try {
            $payload = $this->callService->joinStream($request->user(), (int) $id);

            return $this->sendResponse($payload, 'Joined livestream');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to join livestream.', 500);
        }
    }

    public function leave(Request $request, int|string $id): JsonResponse
    {
        try {
            $payload = $this->callService->leaveStream($request->user(), (int) $id);

            return $this->sendResponse($payload, 'Left livestream');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to leave livestream.', 500);
        }
    }

    public function inviteHost(Request $request, int|string $id): JsonResponse
    {
        try {
            $request->validate(['user_id' => 'required|integer|exists:users,id']);
            $result = $this->callService->inviteStreamHost($request->user(), (int) $id, (int) $request->input('user_id'));

            return $this->sendResponse($result, $result['message']);
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to invite co-host.', 500);
        }
    }

    public function acceptHost(Request $request, int|string $id): JsonResponse
    {
        try {
            $payload = $this->callService->acceptStreamHost($request->user(), (int) $id);

            return $this->sendResponse($payload, 'Joined as co-host');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to accept co-host invite.', 500);
        }
    }

    public function rejectHost(Request $request, int|string $id): JsonResponse
    {
        try {
            $result = $this->callService->rejectStreamHost($request->user(), (int) $id);

            return $this->sendResponse($result, $result['message']);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to reject co-host invite.', 500);
        }
    }

    public function revokeHost(Request $request, int|string $id): JsonResponse
    {
        try {
            $request->validate(['user_id' => 'required|integer']);
            $result = $this->callService->revokeStreamHost($request->user(), (int) $id, (int) $request->input('user_id'));

            return $this->sendResponse($result, $result['message']);
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to revoke co-host.', 500);
        }
    }

    public function end(Request $request, int|string $id): JsonResponse
    {
        try {
            $payload = $this->callService->endStream($request->user(), (int) $id);

            return $this->sendResponse($payload, 'Livestream ended');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to end livestream.', 500);
        }
    }

    public function active(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $scope = (string) $request->input('scope', 'all');

            $streams = $this->callService->activeStreams($perPage, $scope);

            return $this->sendResponse($streams, 'Active livestreams retrieved');
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve livestreams.', 500);
        }
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            $stream = $this->callService->getStream($request->user(), (int) $id);

            return $this->sendResponse($stream, 'Livestream details retrieved');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve livestream.', 500);
        }
    }

    public function indexComments(Request $request, int|string $id): JsonResponse
    {
        try {
            $page = max((int) $request->input('page', 1), 1);
            $perPage = min(max((int) $request->input('per_page', 10), 1), 100);

            $data = $this->callService->getStreamComments($request->user(), (int) $id, $page, $perPage);

            return $this->sendResponse($data, 'Livestream comments.');
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve comments.', 500);
        }
    }

    public function storeComment(Request $request, int|string $id): JsonResponse
    {
        try {
            $request->validate([
                'comment' => 'required|string|max:1000',
                'parent_id' => 'nullable|integer',
            ]);

            $comment = $this->callService->addStreamComment(
                user: $request->user(),
                streamId: (int) $id,
                commentText: (string) $request->input('comment'),
                parentId: $request->filled('parent_id') ? (int) $request->input('parent_id') : null,
            );

            return $this->sendResponse($comment, 'Comment added successfully', 201);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to add comment.', 500);
        }
    }

    public function toggleCommentLike(Request $request, int|string $commentId): JsonResponse
    {
        try {
            $result = $this->callService->toggleCommentLike($request->user(), (int) $commentId);

            return $this->sendResponse($result, $result['message']);
        } catch (ModelNotFoundException) {
            return $this->sendError('Comment not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to update comment like.', 500);
        }
    }

    public function toggleStreamLike(Request $request, int|string $id): JsonResponse
    {
        try {
            $result = $this->callService->toggleStreamLike($request->user(), (int) $id);

            return $this->sendResponse($result, $result['message']);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to update livestream like.', 500);
        }
    }

    public function deleteComment(Request $request, int|string $commentId): JsonResponse
    {
        try {
            $result = $this->callService->deleteStreamComment($request->user(), (int) $commentId);

            return $this->sendResponse($result, 'Comment deleted successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Comment not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to delete comment.', 500);
        }
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:1000',
                'stream_layout' => 'nullable|string|in:solo,duo,group,party,stage',
                'allow_gift_bet' => 'nullable|boolean',
                'custom_questions' => 'nullable|array',
                'video' => 'nullable|file',
            ]);

            if ($request->hasFile('video')) {
                $validated['video_url'] = $this->uploadFile($request->file('video'), 'communication/livestreams/videos', 's3');
            }
            unset($validated['video']);

            $stream = $this->callService->updateStream($request->user(), (int) $id, $validated);

            return $this->sendResponse($stream, 'Livestream updated successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to update livestream.', 500);
        }
    }

    public function storePoll(Request $request, int|string $id): JsonResponse
    {
        try {
            $request->validate([
                'question' => 'required|string|max:255',
                'options' => 'required|array|min:2|max:10',
                'options.*' => 'required|string|max:255',
            ]);

            $poll = $this->callService->createStreamPoll(
                host: $request->user(),
                streamId: (int) $id,
                question: (string) $request->input('question'),
                options: (array) $request->input('options'),
            );

            return $this->sendResponse($poll, 'Poll created and started successfully', 201);
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to create poll.', 500);
        }
    }

    public function showPoll(Request $request, int|string $id): JsonResponse
    {
        try {
            $poll = $this->callService->getStreamPoll($request->user(), (int) $id);

            if ($poll === null) {
                return $this->sendResponse(null, 'No active poll for this livestream.');
            }

            return $this->sendResponse($poll, 'Active poll retrieved successfully');
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve poll.', 500);
        }
    }

    public function votePoll(Request $request, int|string $pollId): JsonResponse
    {
        try {
            $request->validate([
                'option_id' => 'required|integer',
            ]);

            $poll = $this->callService->voteStreamPoll(
                user: $request->user(),
                pollId: (int) $pollId,
                optionId: (int) $request->input('option_id'),
            );

            return $this->sendResponse($poll, 'Vote recorded successfully');
        } catch (ModelNotFoundException) {
            return $this->sendError('Poll not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to record vote.', 500);
        }
    }

    public function endPoll(Request $request, int|string $pollId): JsonResponse
    {
        try {
            $poll = $this->callService->endStreamPoll($request->user(), (int) $pollId);

            return $this->sendResponse($poll, 'Poll ended successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Poll not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to end poll.', 500);
        }
    }

    public function amountPresets(): JsonResponse
    {
        try {
            $presets = $this->callService->getAmountPresets()->map(static function ($preset): array {
                return [
                    'id' => (int) $preset->id,
                    'amount' => (float) $preset->amount,
                    'sort_order' => (int) $preset->sort_order,
                ];
            })->values()->all();

            $gifts = Gift::where('is_active', true)
                ->orderBy('coin_cost', 'asc')
                ->get()
                ->map(static function ($gift): array {
                    return [
                        'id' => (int) $gift->id,
                        'title' => (string) $gift->title,
                        'icon_url' => (string) $gift->icon_url,
                        'coin_cost' => (int) $gift->coin_cost,
                        'sort_order' => (int) $gift->sort_order,
                    ];
                })->values()->all();

            return $this->sendResponse([
                'presets' => $presets,
                'gifts' => $gifts,
            ], 'Gifting configuration retrieved successfully');
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve gifting configuration.', 500);
        }
    }

    public function giftPriceConfiguration(): JsonResponse
    {
        try {
            $configs = LivestreamGamificationPreset::active()
                ->get()
                ->map(static function ($item): array {
                    return [
                        'amount' => (float) $item->amount,
                        'sort_order' => (int) $item->sort_order,
                    ];
                })->values()->all();

            return $this->sendResponse($configs, 'Price configuration retrieved successfully');
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve price configuration.', 500);
        }
    }

    public function getQuestions(Request $request, int|string $id): JsonResponse
    {
        try {
            $quantity = (int) $request->input('quantity', 5);
            $topic = $request->input('topic') ? (string) $request->input('topic') : null;

            $result = $this->callService->getLivestreamQuestions(
                streamId: (int) $id,
                quantity: $quantity,
                topic: $topic
            );

            return $this->sendResponse($result, 'Questions retrieved successfully');
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve questions.', 500);
        }
    }

    public function generateQuestions(Request $request): JsonResponse
    {
        try {
            $quantity = (int) $request->input('quantity', 5);
            $topic = $request->input('topic') ? (string) $request->input('topic') : null;

            $result = $this->callService->generateAiQuestions(
                quantity: $quantity,
                topic: $topic
            );

            return $this->sendResponse($result, 'AI questions generated successfully');
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to generate questions.', 500);
        }
    }

    public function placeGameBet(Request $request, int|string $id): JsonResponse
    {
        try {
            $request->validate([
                'selected_answer' => 'required|string|in:true,false',
                'amount' => 'required|numeric|min:1',
                'question_index' => 'required|integer',
            ]);

            $result = $this->callService->placeGameBet(
                user: $request->user(),
                streamId: (int) $id,
                data: $request->only(['selected_answer', 'amount', 'question_index'])
            );

            $message = $result['is_correct'] ? 'Correct! Bet won.' : 'Incorrect! Bet lost.';

            return $this->sendResponse($result, $message);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to place bet.', 500);
        }
    }

    public function storeGift(Request $request, int|string $id): JsonResponse
    {
        try {
            $request->validate([
                'gift_id' => 'required_without:amount|nullable|integer',
                'amount' => 'required_without:gift_id|nullable|numeric|min:0.01',
                'gift_type' => 'nullable|string|max:255',
                'message' => 'nullable|string|max:1000',
                'sort_order' => 'nullable|integer',
            ]);

            $payload = $this->callService->sendStreamGift(
                sender: $request->user(),
                streamId: (int) $id,
                data: $request->only(['gift_id', 'gift_type', 'amount', 'message', 'sort_order'])
            );

            $message = ! empty($payload['payment_intent_id'])
                ? 'Gift payment intent created successfully'
                : 'Gift sent successfully';

            return $this->sendResponse($payload, $message, 201);
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError($exception->getMessage() ?: 'Failed to send gift.', 500);
        }
    }

    public function confirmGift(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'payment_intent_id' => 'required|string',
            ]);

            $payload = $this->callService->confirmStreamGiftPayment(
                $request->user(),
                $request->input('payment_intent_id')
            );

            return $this->sendResponse($payload, 'Gift payment confirmed successfully');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError($exception->getMessage() ?: 'Failed to confirm gift payment.', 500);
        }
    }

    public function indexGifts(Request $request, int|string $id): JsonResponse
    {
        try {
            $page = max((int) $request->input('page', 1), 1);
            $perPage = min(max((int) $request->input('per_page', 10), 1), 100);

            $data = $this->callService->getStreamGifts($request->user(), (int) $id, $page, $perPage);

            return $this->sendResponse($data, 'Livestream gifts retrieved successfully');
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve gifts.', 500);
        }
    }

    public function topGifters(Request $request, int|string $id): JsonResponse
    {
        try {
            $data = $this->callService->getTopGifters((int) $id);

            return $this->sendResponse($data, 'Top 10 stream gifters retrieved successfully');
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve top gifters.', 500);
        }
    }

    public function hostItems(Request $request, int|string $id): JsonResponse
    {
        try {
            $data = $this->callService->getHostItems((int) $id);

            return $this->sendResponse($data, 'Host items retrieved successfully');
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve host items.', 500);
        }
    }

    public function getFeaturedItems(Request $request, int|string $id): JsonResponse
    {
        try {
            $data = $this->callService->getFeaturedItems((int) $id);

            return $this->sendResponse($data, 'Featured items retrieved successfully');
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve featured items.', 500);
        }
    }

    public function featureItem(Request $request, int|string $id): JsonResponse
    {
        try {
            $request->validate([
                'item_type' => 'required|string|in:product,service,job,event',
                'item_id' => 'required|integer',
                'is_featured' => 'required|boolean',
            ]);

            $data = $this->callService->featureItem(
                $request->user(),
                (int) $id,
                $request->only(['item_type', 'item_id', 'is_featured'])
            );

            return $this->sendResponse($data, 'Item featured status updated successfully');
        } catch (ModelNotFoundException) {
            return $this->sendError('Livestream not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError($exception->getMessage() ?: 'Failed to feature item.', 500);
        }
    }
}
