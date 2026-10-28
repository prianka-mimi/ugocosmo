@extends('layouts.dashboard')

@section('page-title', 'Contact Messages')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>All Conversations</h3>
        <form method="GET" action="{{ route('admin.conversations.index') }}" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <div class="search-box">
                <i class="fa-solid fa-search"></i>
                <input type="text" name="search" placeholder="Search participants..." value="{{ request('search') }}" onchange="this.form.submit()" />
            </div>
            @if(request()->anyFilled(['search']))
                <a href="{{ route('admin.conversations.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.4rem 0.75rem; font-size: 0.875rem;"><i class="fa-solid fa-rotate-left"></i></a>
            @endif
        </form>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th>Participants</th>
                <th>Type</th>
                <th>Last Message</th>
                <th>Last Activity</th>
                <th style="width: 80px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($conversations as $conv)
            <tr>
                <td>
                    <div style="font-size: 0.8125rem; font-weight: 500;">
                        {{ $conv->participantOne?->full_name ?? 'User #'.$conv->participant_1 }}
                    </div>
                    <div style="font-size: 0.75rem; color: #888;">
                        with {{ $conv->participantTwo?->full_name ?? 'User #'.$conv->participant_2 }}
                    </div>
                </td>
                <td>
                    <span class="badge" style="background: #0891b215; color: #0891b2; text-transform: capitalize;">
                        {{ $conv->message_type ?? 'general' }}
                    </span>
                </td>
                <td style="font-size: 0.8125rem; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #888;">
                    {{ $conv->latestMessage?->content ?? 'No messages' }}
                </td>
                <td style="font-size: 0.75rem; color: #888;">
                    {{ $conv->last_message_at ? $conv->last_message_at->diffForHumans() : $conv->created_at->diffForHumans() }}
                </td>
                <td style="text-align: right;">
                    <a href="{{ route('admin.conversations.show', $conv) }}" class="btn-view-icon" title="View Conversation"><i class="fa-solid fa-eye"></i></a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($conversations->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No conversations found.</p>
    @endif
</div>
@endsection
