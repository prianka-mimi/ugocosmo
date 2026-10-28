<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Communication\Http\Requests\MessageReactionRequest;
use Modules\Communication\Http\Resources\MessageInfoResource;
use Modules\Communication\Http\Resources\MessageResource;
use Modules\Communication\Services\MessageService;
use Modules\Communication\Traits\ApiResponseTrait;
use Throwable;

class MessageController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly MessageService $messageService) {}

    public function info(Request $request, string $id): JsonResponse
    {
        try {
            $info = $this->messageService->getMessageInfo($id);

            return $this->sendResponse(
                (new MessageInfoResource($info))->toArray($request),
                'Message info retrieved successfully'
            );
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Message not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve message info.', 500);
        }
    }

    public function react(MessageReactionRequest $request, string $id): JsonResponse
    {
        try {
            $message = $this->messageService->addReaction(
                $id,
                (int) $request->user()->id,
                $request->input('reaction')
            );

            return $this->sendResponse(
                (new MessageResource($message))->toArray($request),
                'Reaction updated successfully'
            );
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->sendError('Message not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to update reaction.', 500);
        }
    }
}
