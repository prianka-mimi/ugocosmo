<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Http\Requests\EndCallRequest;
use Modules\Communication\Http\Requests\InitiateCallRequest;
use Modules\Communication\Http\Requests\KickCallParticipantRequest;
use Modules\Communication\Http\Requests\MuteCallParticipantRequest;
use Modules\Communication\Jobs\HandleMiscallJob;
use Modules\Communication\Services\CallService;
use Modules\Communication\Traits\ApiResponseTrait;
use RuntimeException;
use Throwable;

class CallController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly CallService $callService) {}

    public function initiate(InitiateCallRequest $request): JsonResponse
    {
        try {
            $payload = $this->callService->initiateCall(
                $request->user(),
                (int) $request->input('conversation_id'),
                (string) $request->input('call_type')
            );

            // Fetch delay seconds from config based on call scale
            $delaySeconds = ($payload['scale_type'] === 'group')
              ? config('communication.call_ring_timeout_seconds_group', 60)
              : config('communication.call_ring_timeout_seconds_one_to_one', 30);

            // Dispatch job with the calculated delay
            HandleMiscallJob::dispatch($payload)->delay(now()->addSeconds((int) $delaySeconds));

            return $this->sendResponse($payload, 'Call initiated successfully', 201);
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (RuntimeException $exception) {
            return $this->sendError($exception->getMessage(), 500);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to initiate call.', 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $payload = $this->callService->getCall($request->user(), $id);

            return $this->sendResponse($payload, 'Call details retrieved successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Call not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve call details.', 500);
        }
    }

    public function checkActive(Request $request, int $id): JsonResponse
    {
        try {
            $payload = $this->callService->checkActiveCall($request->user(), $id);

            return $this->sendResponse($payload, 'Active call status retrieved successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Conversation not found.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to retrieve active call status.', 500);
        }
    }

    public function join(Request $request, int $id): JsonResponse
    {
        try {
            $payload = $this->callService->joinCall($request->user(), $id);

            return $this->sendResponse($payload, 'Joined call successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Call not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (RuntimeException $exception) {
            return $this->sendError($exception->getMessage(), 500);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to join call.', 500);
        }
    }

    public function leave(Request $request, int $id): JsonResponse
    {
        try {
            $payload = $this->callService->leaveCall($request->user(), $id);

            return $this->sendResponse($payload, 'Left call successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Call not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to leave call.', 500);
        }
    }

    public function kick(KickCallParticipantRequest $request, int $id): JsonResponse
    {
        try {
            $payload = $this->callService->kickParticipant(
                $request->user(),
                $id,
                (int) $request->input('participant_id')
            );

            return $this->sendResponse($payload, 'Participant removed from call successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Call not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to remove participant from call.', 500);
        }
    }

    public function muteParticipant(MuteCallParticipantRequest $request, int $id): JsonResponse
    {
        try {
            $payload = $this->callService->muteParticipant(
                $request->user(),
                $id,
                (int) $request->input('participant_id'),
                (string) $request->input('media_kind', 'audio'),
                (bool) $request->boolean('muted', true)
            );

            return $this->sendResponse($payload, 'Participant media updated successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Call not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to update participant media state.', 500);
        }
    }

    public function end(EndCallRequest $request, int $id): JsonResponse
    {
        try {
            $payload = $this->callService->endCall(
                $request->user(),
                $id,
                $request->input('status')
            );

            return $this->sendResponse($payload, 'Call ended successfully');
        } catch (AuthorizationException $exception) {
            return $this->sendError($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return $this->sendError('Call not found.', 404);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = (string) collect($errors)->flatten()->first();

            return $this->sendError($message !== '' ? $message : 'The given data is invalid.', 422, $errors);
        } catch (Throwable $exception) {
            report($exception);

            return $this->sendError('Failed to end call.', 500);
        }
    }
}
