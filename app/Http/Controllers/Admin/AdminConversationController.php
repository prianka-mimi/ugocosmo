<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminConversationController extends Controller
{
    public function index()
    {
        $query = Conversation::with(['participantOne', 'participantTwo', 'latestMessage']);

        if ($search = request('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('participantOne', fn ($p) => $p->where('full_name', 'like', "%{$search}%"))
                    ->orWhereHas('participantTwo', fn ($p) => $p->where('full_name', 'like', "%{$search}%"));
            });
        }
        if ($type = request('type')) {
            $query->where('message_type', $type);
        }

        $conversations = $query->latestFirst()->paginate(20);

        return view('modules.admin.conversations.index', compact('conversations'));
    }

    public function show(Conversation $conversation)
    {
        $conversation->load(['participantOne', 'participantTwo', 'messages.sender', 'order']);
        $supervisors = User::where('role', 'supervisor')
            ->where('status', 'active')
            ->whereHas('profile')
            ->select('id', 'name', 'email')
            ->get();

        return view('modules.admin.conversations.show', compact('conversation', 'supervisors'));
    }

    public function reply(Conversation $conversation)
    {
        $content = request()->input('content');

        if (! $content || trim($content) === '') {
            return back()->with('error', 'Reply message cannot be empty.');
        }

        try {
            DB::beginTransaction();

            $adminProfile = auth()->user()->profile;

            if (! $adminProfile) {
                throw new \Exception('Admin profile not found.');
            }

            // Admin/supervisor with the reply permission can reply to any conversation
            // The web-admin middleware already authorizes based on role/permission
            $adminProfileId = (int) $adminProfile->id;

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $adminProfileId,
                'content' => $content,
                'is_read' => false,
            ]);

            $conversation->update([
                'last_message_at' => now(),
            ]);

            AdminAuditLog::create([
                'admin_id' => $adminProfileId,
                'action' => 'conversation_reply',
                'entity_type' => 'message',
                'entity_id' => $message->id,
                'new_value' => ['conversation_id' => $conversation->id, 'content_preview' => substr($content, 0, 100)],
                'ip_address' => request()->ip(),
            ]);

            DB::commit();

            return back()->with('success', 'Reply sent successfully.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Conversation reply failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    public function assignSupervisor(Conversation $conversation)
    {
        $supervisorId = request()->input('supervisor_id');

        if (! $supervisorId) {
            return back()->with('error', 'Please select a supervisor.');
        }

        $supervisor = User::where('id', $supervisorId)->where('role', 'supervisor')->first();

        if (! $supervisor) {
            return back()->with('error', 'Invalid supervisor selected.');
        }

        $supervisorProfile = $supervisor->profile;

        if (! $supervisorProfile) {
            return back()->with('error', 'Supervisor has no profile.');
        }

        try {
            DB::beginTransaction();

            // Update conversation participants to include the supervisor
            $participant1Id = (int) $conversation->participant_1;
            $participant2Id = (int) $conversation->participant_2;

            // Replace the admin participant with the supervisor
            $adminProfile = auth()->user()->profile;
            $adminProfileId = (int) $adminProfile->id;

            if ($participant1Id === $adminProfileId) {
                $conversation->update(['participant_1' => $supervisorProfile->id]);
            } elseif ($participant2Id === $adminProfileId) {
                $conversation->update(['participant_2' => $supervisorProfile->id]);
            } else {
                // Admin is not a participant - add supervisor as participant 2
                $conversation->update(['participant_2' => $supervisorProfile->id]);
            }

            AdminAuditLog::create([
                'admin_id' => $adminProfileId,
                'action' => 'conversation_assigned',
                'entity_type' => 'conversation',
                'entity_id' => $conversation->id,
                'new_value' => ['assigned_to' => $supervisor->name, 'supervisor_id' => $supervisor->id],
                'ip_address' => request()->ip(),
            ]);

            DB::commit();

            return back()->with('success', "Conversation assigned to supervisor: {$supervisor->name}");
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Conversation assign failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }
}
