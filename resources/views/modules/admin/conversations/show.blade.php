@extends('layouts.dashboard')

@section('page-title', 'Conversation')

@section('content')
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
    <div class="card" style="display: flex; flex-direction: column;">
        <div class="card-header">
            <h3>
                {{ $conversation->participantOne?->full_name ?? 'User #'.$conversation->participant_1 }}
                &harr; {{ $conversation->participantTwo?->full_name ?? 'User #'.$conversation->participant_2 }}
            </h3>
            @if($conversation->order_id)
                <a href="{{ route('admin.orders.show', $conversation->order) }}" class="btn-view" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-receipt"></i> Order #{{ $conversation->order_id }}
                </a>
            @endif
        </div>

        <div style="flex: 1; overflow-y: auto; max-height: 400px; padding: 0.5rem; border: 1px solid #f0f0f0; border-radius: 0.5rem; margin-bottom: 1rem;">
            @forelse($conversation->messages as $message)
                @php
                    $isSenderAdminOrSupervisor = $message->sender?->user && in_array($message->sender->user->role, ['admin', 'supervisor']);
                @endphp
                <div style="display: flex; gap: 0.75rem; margin-bottom: 1rem; {{ $isSenderAdminOrSupervisor ? 'flex-direction: row-reverse;' : '' }}">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <i class="fa-solid fa-user" style="font-size: 0.7rem; color: #bbb;"></i>
                    </div>
                    <div style="max-width: 70%;">
                        <div style="font-size: 0.75rem; color: #888; margin-bottom: 0.2rem; {{ $isSenderAdminOrSupervisor ? 'text-align: right;' : '' }}">
                            {{ $message->sender?->full_name ?? 'Unknown' }}
                            @if($message->sender?->user?->role === 'admin')
                                <span style="font-size: 0.65rem; color: var(--primary); font-weight: bold;">(Admin)</span>
                            @elseif($message->sender?->user?->role === 'supervisor')
                                <span style="font-size: 0.65rem; color: #0891b2; font-weight: bold;">(Supervisor)</span>
                            @endif
                        </div>
                        <div style="background: {{ $isSenderAdminOrSupervisor ? 'var(--primary)' : '#f5f5f5' }}; color: {{ $isSenderAdminOrSupervisor ? '#fff' : '#333' }}; padding: 0.6rem 0.9rem; border-radius: 0.75rem; font-size: 0.875rem; line-height: 1.5;">
                            {{ $message->content }}
                        </div>
                        <div style="font-size: 0.65rem; color: #bbb; margin-top: 0.2rem; {{ $isSenderAdminOrSupervisor ? 'text-align: right;' : '' }}">
                            {{ $message->created_at->format('M d, Y h:i A') }}
                            @if($message->is_read)
                                <i class="fa-solid fa-check-double" style="color: #22c55e; margin-left: 0.25rem;"></i>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No messages in this conversation.</p>
            @endforelse
        </div>

        <form method="POST" action="{{ route('admin.conversations.reply', $conversation) }}" style="display: flex; gap: 0.5rem;">
            @csrf
            <textarea name="content" rows="2" required placeholder="Type your reply..." style="flex: 1; padding: 0.6rem 0.9rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.875rem; font-family: inherit; outline: none; resize: none;"></textarea>
            <button type="submit" class="btn-primary" style="align-self: flex-end; padding: 0.6rem 1.2rem;">
                <i class="fa-solid fa-paper-plane"></i> Send
            </button>
        </form>
    </div>

    <div>
        <div class="card" style="margin-bottom: 1rem;">
            <div class="card-header"><h3>Details</h3></div>
            <table>
                <tr><td style="font-weight: 500; font-size: 0.75rem;">Type</td><td style="font-size: 0.8125rem;">{{ $conversation->message_type ?? 'general' }}</td></tr>
                <tr><td style="font-weight: 500; font-size: 0.75rem;">Created</td><td style="font-size: 0.8125rem;">{{ $conversation->created_at->format('M d, Y') }}</td></tr>
                <tr><td style="font-weight: 500; font-size: 0.75rem;">Messages</td><td style="font-size: 0.8125rem;">{{ $conversation->messages->count() }}</td></tr>
                <tr><td style="font-weight: 500; font-size: 0.75rem;">Participant 1</td><td style="font-size: 0.8125rem;">{{ $conversation->participantOne?->full_name ?? '#' . $conversation->participant_1 }}</td></tr>
                <tr><td style="font-weight: 500; font-size: 0.75rem;">Participant 2</td><td style="font-size: 0.8125rem;">{{ $conversation->participantTwo?->full_name ?? '#' . $conversation->participant_2 }}</td></tr>
            </table>
        </div>

        @canPermission('conversations.assign')
        <div class="card">
            <div class="card-header"><h3>Assign to Supervisor</h3></div>
            <form method="POST" action="{{ route('admin.conversations.assign', $conversation) }}" style="display: flex; flex-direction: column; gap: 0.75rem;">
                @csrf
                <select name="supervisor_id" required style="width: 100%; padding: 0.6rem 0.9rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.875rem;">
                    <option value="">Select supervisor...</option>
                    @foreach($supervisors as $sup)
                        <option value="{{ $sup->id }}">{{ $sup->name }} ({{ $sup->email }})</option>
                    @endforeach
                </select>
                <button type="submit" class="btn-primary" style="width: 100%;">
                    <i class="fa-solid fa-user-tag"></i> Assign
                </button>
            </form>
        </div>
        @endcanPermission
    </div>
</div>

<div style="margin-top: 1rem;">
    <a href="{{ route('admin.conversations.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem;"><i class="fa-solid fa-arrow-left"></i> Back to Conversations</a>
</div>
@endsection
