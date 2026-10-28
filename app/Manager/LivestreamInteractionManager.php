<?php

namespace App\Manager;

use App\Models\Livestream;
use App\Models\LivestreamComment;
use App\Models\LivestreamGift;
use App\Models\LivestreamInvite;
use App\Models\LivestreamJoinRequest;
use Illuminate\Support\Facades\Auth;

class LivestreamInteractionManager
{
    protected NotificationManager $notificationManager;

    public function __construct(NotificationManager $notificationManager)
    {
        $this->notificationManager = $notificationManager;
    }

    // ─── Comments ────────────────────────────────────────────────

    public function comments(Livestream $livestream)
    {
        return LivestreamComment::forLivestream((int) $livestream->id)
            ->oldestFirst()
            ->with('user')
            ->get();
    }

    /**
     * Send a comment on a livestream and notify the host.
     *
     * ─── Notification ─────────────────────────────────────────────────
     * Recipient: Livestream host
     * Type:      livestream.comment
     * Title:     "New Comment on Your Livestream"
     * Message:   "{sender_name} commented: {content_preview}"
     */
    public function sendComment(Livestream $livestream, string $content): LivestreamComment
    {
        $this->ensureLive($livestream);
        $profileId = (int) $this->profileId();

        $comment = LivestreamComment::create([
            'livestream_id' => $livestream->id,
            'user_id' => $profileId,
            'content' => $content,
        ]);

        $comment = $comment->load('user');

        // ── Notify the host (if the commenter is not the host) ───
        if ((int) $livestream->host_id !== $profileId) {
            $senderName = $comment->user?->full_name ?? 'Someone';
            $this->notificationManager->dispatch(
                (int) $livestream->host_id,
                'livestream.comment',
                'New Comment on Your Livestream',
                "{$senderName} commented: ".mb_substr($content, 0, 80),
                [
                    'livestream_id' => $livestream->id,
                    'comment_id' => $comment->id,
                    'sender_id' => $profileId,
                ]
            );
        }

        return $comment;
    }

    // ─── Gifts ────────────────────────────────────────────────────

    public function gifts(Livestream $livestream)
    {
        return LivestreamGift::forLivestream((int) $livestream->id)
            ->with('sender')
            ->latest()
            ->get();
    }

    /**
     * Send a gift on a livestream and notify the host.
     *
     * ─── Notification ─────────────────────────────────────────────────
     * Recipient: Livestream host
     * Type:      livestream.gift
     * Title:     "New Gift Received 🎁"
     * Message:   "{sender_name} sent a {gift_type} gift on your livestream!"
     */
    public function sendGift(Livestream $livestream, array $data): LivestreamGift
    {
        $this->ensureLive($livestream);
        $profileId = (int) $this->profileId();

        $gift = LivestreamGift::create([
            'livestream_id' => $livestream->id,
            'sender_id' => $profileId,
            'gift_type' => $data['gift_type'],
            'amount' => $data['amount'] ?? null,
            'message' => $data['message'] ?? null,
        ]);

        $gift = $gift->load('sender');

        // ── Notify the host (if the sender is not the host) ──────
        if ((int) $livestream->host_id !== $profileId) {
            $senderName = $gift->sender?->full_name ?? 'Someone';
            $this->notificationManager->dispatch(
                (int) $livestream->host_id,
                'livestream.gift',
                'New Gift Received 🎁',
                "{$senderName} sent a {$data['gift_type']} gift on your livestream!",
                [
                    'livestream_id' => $livestream->id,
                    'gift_id' => $gift->id,
                    'sender_id' => $profileId,
                    'gift_type' => $data['gift_type'],
                ]
            );
        }

        return $gift;
    }

    // ─── Join Requests ────────────────────────────────────────────

    public function joinRequests(Livestream $livestream)
    {
        return LivestreamJoinRequest::forLivestream((int) $livestream->id)
            ->with('requester')
            ->latest()
            ->get();
    }

    /**
     * Request to join a livestream and notify the host.
     *
     * ─── Notification ─────────────────────────────────────────────────
     * Recipient: Livestream host
     * Type:      livestream.join_request
     * Title:     "New Join Request"
     * Message:   "{requester_name} wants to join your livestream."
     */
    public function requestToJoin(Livestream $livestream): LivestreamJoinRequest
    {
        $this->ensureLive($livestream);
        $profileId = (int) $this->profileId();

        $existing = LivestreamJoinRequest::forLivestream((int) $livestream->id)
            ->byRequester($profileId)
            ->first();

        if ($existing) {
            throw new \Exception('You have already requested to join this livestream', 422);
        }

        $request = LivestreamJoinRequest::create([
            'livestream_id' => $livestream->id,
            'requester_id' => $profileId,
            'status' => 'pending',
        ]);

        $request = $request->load('requester');

        // ── Notify the host ──────────────────────────────────────
        $requesterName = $request->requester?->full_name ?? 'Someone';
        $this->notificationManager->dispatch(
            (int) $livestream->host_id,
            'livestream.join_request',
            'New Join Request',
            "{$requesterName} wants to join your livestream.",
            [
                'livestream_id' => $livestream->id,
                'join_request_id' => $request->id,
                'requester_id' => $profileId,
            ]
        );

        return $request;
    }

    /**
     * Respond to a join request (accept/decline) and notify the requester.
     *
     * ─── Notification ─────────────────────────────────────────────────
     * Recipient: Requester
     * Type:      livestream.join_accepted / livestream.join_declined
     * Title:     "Join Request {Accepted/Declined}"
     * Message:   "Your request to join {livestream_title} has been {accepted/declined}."
     */
    public function respondToJoinRequest(Livestream $livestream, LivestreamJoinRequest $joinRequest, string $status): LivestreamJoinRequest
    {
        $this->ensureHost($livestream);

        if ((int) $joinRequest->livestream_id !== (int) $livestream->id) {
            abort(404);
        }

        $joinRequest->update([
            'status' => $status,
            'joined_at' => $status === 'accepted' ? now() : null,
        ]);

        $joinRequest = $joinRequest->fresh()->load('requester');

        // ── Notify the requester ──────────────────────────────────
        $notificationType = $status === 'accepted' ? 'livestream.join_accepted' : 'livestream.join_declined';
        $title = $status === 'accepted' ? 'Join Request Accepted' : 'Join Request Declined';
        $message = "Your request to join {$livestream->title} has been {$status}.";

        $this->notificationManager->dispatch(
            (int) $joinRequest->requester_id,
            $notificationType,
            $title,
            $message,
            [
                'livestream_id' => $livestream->id,
                'join_request_id' => $joinRequest->id,
            ]
        );

        return $joinRequest;
    }

    // ─── Invites ──────────────────────────────────────────────────

    /**
     * Invite a user to a livestream and notify them.
     *
     * ─── Notification ─────────────────────────────────────────────────
     * Recipient: Invitee
     * Type:      livestream.invite
     * Title:     "Livestream Invitation"
     * Message:   "{inviter_name} invited you to join their livestream: {livestream_title}."
     */
    public function invite(Livestream $livestream, int $inviteeId): LivestreamInvite
    {
        $this->ensureHost($livestream);
        $profileId = (int) $this->profileId();

        $existing = LivestreamInvite::forLivestream((int) $livestream->id)
            ->where('invitee_id', $inviteeId)
            ->first();

        if ($existing) {
            throw new \Exception('User has already been invited to this livestream', 422);
        }

        $invite = LivestreamInvite::create([
            'livestream_id' => $livestream->id,
            'inviter_id' => $profileId,
            'invitee_id' => $inviteeId,
            'status' => 'pending',
        ]);

        $invite = $invite->load(['inviter', 'invitee']);

        // ── Notify the invitee ────────────────────────────────────
        $inviterName = $invite->inviter?->full_name ?? 'The host';
        $this->notificationManager->dispatch(
            $inviteeId,
            'livestream.invite',
            'Livestream Invitation',
            "{$inviterName} invited you to join their livestream: {$livestream->title}.",
            [
                'livestream_id' => $livestream->id,
                'invite_id' => $invite->id,
                'inviter_id' => $profileId,
            ]
        );

        return $invite;
    }

    /**
     * Respond to an invite (accept/decline) and notify the inviter.
     *
     * ─── Notification ─────────────────────────────────────────────────
     * Recipient: Inviter
     * Type:      livestream.invite_accepted / livestream.invite_declined
     * Title:     "Invitation {Accepted/Declined}"
     * Message:   "{invitee_name} has {accepted/declined} your invitation to join the livestream."
     */
    public function respondToInvite(LivestreamInvite $invite, string $status): LivestreamInvite
    {
        $profileId = (int) $this->profileId();

        if ((int) $invite->invitee_id !== $profileId) {
            abort(404);
        }

        $invite->update(['status' => $status]);

        $invite = $invite->fresh()->load(['inviter', 'invitee']);

        // ── Notify the inviter ────────────────────────────────────
        $inviteeName = $invite->invitee?->full_name ?? 'The invitee';
        $notificationType = $status === 'accepted' ? 'livestream.invite_accepted' : 'livestream.invite_declined';
        $title = $status === 'accepted' ? 'Invitation Accepted' : 'Invitation Declined';
        $message = "{$inviteeName} has {$status} your invitation to join the livestream.";

        $this->notificationManager->dispatch(
            (int) $invite->inviter_id,
            $notificationType,
            $title,
            $message,
            [
                'livestream_id' => $invite->livestream_id,
                'invite_id' => $invite->id,
            ]
        );

        return $invite;
    }

    // ─── Engagement Stats ─────────────────────────────────────────

    public function engagement(Livestream $livestream): array
    {
        $commentCount = LivestreamComment::forLivestream((int) $livestream->id)->count();
        $giftCount = LivestreamGift::forLivestream((int) $livestream->id)->count();
        $giftTotal = LivestreamGift::forLivestream((int) $livestream->id)->sum('amount');
        $joinCount = LivestreamJoinRequest::forLivestream((int) $livestream->id)
            ->where('status', 'accepted')->count();

        return [
            'viewer_count' => $livestream->viewer_count,
            'comment_count' => $commentCount,
            'gift_count' => $giftCount,
            'gift_total' => (float) $giftTotal,
            'participant_count' => $joinCount,
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────

    protected function ensureHost(Livestream $livestream): void
    {
        if ((int) $livestream->host_id !== (int) $this->profileId()) {
            throw new \Exception('Only the livestream host can perform this action', 403);
        }
    }

    protected function ensureLive(Livestream $livestream): void
    {
        if (! $livestream->is_live) {
            throw new \Exception('This livestream is no longer active', 400);
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
