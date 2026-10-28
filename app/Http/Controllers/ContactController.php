<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Manager\ConversationManager;
use App\Models\Conversation;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ContactController - Allows users to send messages to admin/support.
 *
 * Uses the existing conversation system. User starts a conversation
 * with the first available admin profile. Admin/supervisors can
 * reply via the web panel.
 */
class ContactController extends Controller
{
    protected ConversationManager $conversationManager;

    public function __construct(ConversationManager $conversationManager)
    {
        $this->conversationManager = $conversationManager;
    }

    /**
     * Retrieve the support conversation and its messages.
     */
    public function index(): JsonResponse
    {
        try {
            $user = auth('jwt')->user();
            $userProfile = $user->profile;

            if (! $userProfile) {
                throw new \Exception('Profile not found.', 404);
            }

            // Find the support conversation (reusing the same logic as send())
            $conversation = Conversation::where(function ($q) use ($userProfile) {
                $q->where('participant_1', $userProfile->id)
                    ->orWhere('participant_2', $userProfile->id);
            })
                ->where(function ($q) {
                    $q->whereHas('participantOne.user', function ($u) {
                        $u->whereIn('role', ['admin', 'supervisor']);
                    })
                        ->orWhereHas('participantTwo.user', function ($u) {
                            $u->whereIn('role', ['admin', 'supervisor']);
                        });
                })
                ->latest('last_message_at')
                ->first();

            if ($conversation) {
                $conversation->load(['participantOne', 'participantTwo', 'messages.sender']);
                $this->data = [
                    'conversation' => ConversationResource::make($conversation),
                    'messages' => MessageResource::collection($conversation->messages),
                ];
            } else {
                $this->data = [
                    'conversation' => null,
                    'messages' => [],
                ];
            }

            $this->status_message = 'Support conversation retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Contact retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Send a contact/message to admin support.
     * Creates or reuses a conversation between the user and the admin profile.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'subject' => 'sometimes|string|max:255',
            'message' => 'required|string|min:2|max:5000',
        ]);

        try {
            DB::beginTransaction();

            $user = auth('jwt')->user();
            $userProfile = $user->profile;

            if (! $userProfile) {
                throw new \Exception('Complete your profile before sending a message.', 400);
            }

            // Find the first active admin/supervisor user
            $adminUser = User::whereIn('role', ['admin', 'supervisor'])
                ->where('status', 'active')
                ->orderBy('role', 'asc') // admin first
                ->first();

            if (! $adminUser) {
                throw new \Exception('Support is not available at the moment. Please try again later.', 503);
            }

            // Ensure the admin user has a profile
            $adminProfile = $adminUser->profile;
            if (! $adminProfile) {
                $adminProfile = Profile::create([
                    'user_id' => $adminUser->id,
                    'email' => $adminUser->email,
                    'full_name' => $adminUser->name ?? 'Admin',
                    'username' => 'admin_'.$adminUser->id,
                ]);
            }

            $content = $request->input('message');
            $subject = $request->input('subject', 'Contact Request');

            // Start or reuse a conversation with admin
            $conversation = $this->conversationManager->start(
                $adminProfile,
                $content,
                null // no order_id
            );

            DB::commit();

            $this->data = [
                'conversation_id' => $conversation->id,
                'message' => 'Your message has been sent to support. We will get back to you soon.',
            ];
            $this->status_message = 'Message sent successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Contact send error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
