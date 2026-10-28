<?php

namespace App\Http\Controllers;

use App\Http\Requests\InviteRequest;
use App\Http\Requests\LivestreamActionRequest;
use App\Http\Requests\SendCommentRequest;
use App\Http\Requests\SendGiftRequest;
use App\Http\Resources\CommentResource;
use App\Http\Resources\GiftResource;
use App\Manager\LivestreamInteractionManager;
use App\Models\Livestream;
use App\Models\LivestreamInvite;
use App\Models\LivestreamJoinRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LivestreamInteractionController extends Controller
{
    protected LivestreamInteractionManager $interactionManager;

    public function __construct(LivestreamInteractionManager $interactionManager)
    {
        $this->interactionManager = $interactionManager;
    }

    // ─── Comments ────────────────────────────────────────────────

    public function comments(Livestream $livestream): JsonResponse
    {
        try {
            $this->data = CommentResource::collection($this->interactionManager->comments($livestream));
            $this->status_message = 'Comments retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Livestream comments retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function sendComment(SendCommentRequest $request, Livestream $livestream): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = CommentResource::make(
                $this->interactionManager->sendComment($livestream, $request->input('content'))
            );
            DB::commit();

            $this->status_message = 'Comment sent';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream comment error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Gifts ────────────────────────────────────────────────────

    public function gifts(Livestream $livestream): JsonResponse
    {
        try {
            $this->data = GiftResource::collection($this->interactionManager->gifts($livestream));
            $this->status_message = 'Gifts retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Livestream gifts retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function sendGift(SendGiftRequest $request, Livestream $livestream): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = GiftResource::make(
                $this->interactionManager->sendGift($livestream, $request->validated())
            );
            DB::commit();

            $this->status_message = 'Gift sent';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream gift error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Join Requests ────────────────────────────────────────────

    public function joinRequests(Livestream $livestream): JsonResponse
    {
        try {
            $this->data = $this->interactionManager->joinRequests($livestream);
            $this->status_message = 'Join requests retrieved';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Join requests retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function requestToJoin(Livestream $livestream): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->interactionManager->requestToJoin($livestream);
            DB::commit();

            $this->status_message = 'Join request sent';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Join request error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function respondToJoinRequest(LivestreamActionRequest $request, Livestream $livestream, LivestreamJoinRequest $joinRequest): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->interactionManager->respondToJoinRequest(
                $livestream, $joinRequest, $request->input('status')
            );
            DB::commit();

            $this->status_message = 'Join request '.$request->input('status');
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Join request response error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Invites ──────────────────────────────────────────────────

    public function invite(InviteRequest $request, Livestream $livestream): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->interactionManager->invite($livestream, $request->input('invitee_id'));
            DB::commit();

            $this->status_message = 'Invitation sent';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Livestream invite error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function respondToInvite(LivestreamActionRequest $request, Livestream $livestream, LivestreamInvite $invite): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->interactionManager->respondToInvite($invite, $request->input('status'));
            DB::commit();

            $this->status_message = 'Invitation '.$request->input('status');
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Invite response error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Engagement ───────────────────────────────────────────────

    public function engagement(Livestream $livestream): JsonResponse
    {
        try {
            $this->data = $this->interactionManager->engagement($livestream);
            $this->status_message = 'Engagement data retrieved';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Engagement retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
