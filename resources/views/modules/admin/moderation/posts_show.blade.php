@extends('layouts.dashboard')

@section('page-title', 'Post Details - #' . $post->id)

@section('content')
{{-- Post Details Card --}}
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3>Post Information</h3>
        <div class="card-header-actions">
            <a href="{{ route('admin.moderation.posts') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    {{-- Author Info Header --}}
    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; padding-bottom: 1.5rem;">
        @if($post->author?->avatar)
            <img src="{{ asset('storage/' . $post->author->avatar) }}" alt="" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;"/>
        @else
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #f3f4f6; display: flex; align-items: center; justify-content: center; border: 2px solid #e5e7eb;">
                <i class="fa-solid fa-user" style="font-size: 1.25rem; color: #9ca3af;"></i>
            </div>
        @endif
        <div>
            <div style="font-weight: 600; font-size: 1.05rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>{{ $post->author?->full_name ?? 'Unknown' }}</span>
                @if($post->author?->user)
                    <a href="{{ route('admin.users.show', $post->author->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; font-weight: normal;"><i class="fa-solid fa-external-link"></i> View User Profile</a>
                @endif
            </div>
            <div style="font-size: 0.8125rem; color: #6b7280;">
                @if($post->author?->username) @ {{ $post->author->username }} &bull; @endif 
                {{ $post->author?->email ?? ($post->author?->user?->email ?? 'No Email') }}
            </div>
            <div style="display: flex; gap: 0.5rem; margin-top: 0.35rem; align-items: center;">
                @if($post->is_active)
                    <span class="badge" style="background: #f0fdf4; color: #166534; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Active</span>
                @else
                    <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Deactivated</span>
                @endif
                <span style="font-size: 0.75rem; color: #9ca3af;">Posted: {{ $post->created_at->format('d M Y, h:i A') }} ({{ $post->created_at->diffForHumans() }})</span>
            </div>
        </div>
    </div>

    {{-- Post Content & Media --}}
    <div style="font-size: 0.9375rem; line-height: 1.6; color: #1f2937; margin-bottom: 1.5rem; background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #f3f4f6;">
        <div style="font-weight: 600; font-size: 0.8125rem; color: #9ca3af; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Post Content</div>
        <div style="white-space: pre-wrap; word-break: break-word;">{!! nl2br(e($post->content ?? $post->body)) !!}</div>

        {{-- Emoji / Gif --}}
        @if($post->emoji || $post->gif_url)
            <div style="margin-top: 1rem; display: flex; gap: 0.5rem; align-items: center;">
                @if($post->emoji)
                    <span style="font-size: 1.5rem;">{{ $post->emoji }}</span>
                @endif
                @if($post->gif_url)
                    <img src="{{ $post->gif_url }}" alt="Gif" style="max-height: 120px; border-radius: 0.375rem;"/>
                @endif
            </div>
        @endif

        {{-- Attached Media --}}
        @if($post->image_url || $post->video_url || ($post->media && count($post->media) > 0))
            <div style="margin-top: 1.25rem; border-top: 1px solid #e5e7eb; padding-top: 1rem;">
                <div style="font-weight: 600; font-size: 0.8125rem; color: #9ca3af; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em;">Attached Media</div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem;">
                    @if($post->image_url)
                        <div style="border: 1px solid #e5e7eb; border-radius: 0.375rem; overflow: hidden; background: #fff;">
                            <img src="{{ asset('storage/' . $post->image_url) }}" alt="Post Image" style="width: 100%; height: 150px; object-fit: cover;"/>
                            <div style="padding: 0.5rem; font-size: 0.75rem; text-align: center; background: #f9fafb;"><a href="{{ asset('storage/' . $post->image_url) }}" target="_blank">Open Full Image</a></div>
                        </div>
                    @endif

                    @if($post->video_url)
                        <div style="border: 1px solid #e5e7eb; border-radius: 0.375rem; overflow: hidden; background: #fff;">
                            <video src="{{ asset('storage/' . $post->video_url) }}" controls style="width: 100%; height: 150px; object-fit: cover;"></video>
                            <div style="padding: 0.5rem; font-size: 0.75rem; text-align: center; background: #f9fafb;"><a href="{{ asset('storage/' . $post->video_url) }}" target="_blank">Open Full Video</a></div>
                        </div>
                    @endif

                    @if($post->media && is_array($post->media))
                        @foreach($post->media as $mPath)
                            @php
                                $ext = strtolower(pathinfo($mPath, PATHINFO_EXTENSION));
                                $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                                $isVid = in_array($ext, ['mp4', 'mov', 'avi', 'webm']);
                            @endphp
                            <div style="border: 1px solid #e5e7eb; border-radius: 0.375rem; overflow: hidden; background: #fff;">
                                @if($isImg)
                                    <img src="{{ asset('storage/' . $mPath) }}" alt="Post Media Image" style="width: 100%; height: 150px; object-fit: cover;"/>
                                @elseif($isVid)
                                    <video src="{{ asset('storage/' . $mPath) }}" controls style="width: 100%; height: 150px; object-fit: cover;"></video>
                                @else
                                    <div style="height: 150px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; color: #6b7280; font-size: 1.5rem;">
                                        <i class="fa-solid fa-file"></i>
                                    </div>
                                @endif
                                <div style="padding: 0.5rem; font-size: 0.75rem; text-align: center; background: #f9fafb;">
                                    <a href="{{ asset('storage/' . $mPath) }}" target="_blank">Open Attachment</a>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        @endif

        {{-- Polymorphic Attached Item --}}
        @if($post->attached_item_type && $post->attached_item_id)
            <div style="margin-top: 1.25rem; border-top: 1px solid #e5e7eb; padding-top: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-weight: 600; font-size: 0.8125rem; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em;">Attached Item:</span>
                <span class="badge" style="background: #eff6ff; color: #1e40af; text-transform: capitalize; font-size: 0.75rem;">
                    {{ str_replace('App\\Models\\', '', $post->attached_item_type) }} (ID: {{ $post->attached_item_id }})
                </span>
                @if($post->attachedItem)
                    <span style="font-size: 0.875rem; font-weight: 500;">&bull; "{{ $post->attachedItem->name ?? ($post->attachedItem->title ?? '') }}"</span>
                @endif
            </div>
        @endif
    </div>

    {{-- Post Statistics --}}
    <div style="display: flex; gap: 2rem; font-size: 0.8125rem; color: #6b7280; padding: 0 0.5rem;">
        <div><i class="fa-solid fa-thumbs-up" style="color: #9ca3af; margin-right: 0.25rem;"></i> <strong>Likes:</strong> {{ $post->like_count ?? 0 }}</div>
        <div><i class="fa-solid fa-comment" style="color: #9ca3af; margin-right: 0.25rem;"></i> <strong>Comments:</strong> {{ $post->comment_count ?? 0 }}</div>
        <div><i class="fa-solid fa-share" style="color: #9ca3af; margin-right: 0.25rem;"></i> <strong>Shares:</strong> {{ $post->share_count ?? 0 }}</div>
    </div>
</div>

{{-- Reports List Card --}}
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header">
        <h3>Reports Log ({{ $post->reports->count() }})</h3>
    </div>
    
    @if($post->reports->isNotEmpty())
        <table id="dataTable" style="margin-top: 0;">
            <thead>
                <tr>
                    <th style="width: 200px;">Reporter</th>
                    <th>Reason / Description</th>
                    <th style="width: 150px; text-align: right;">Date Reported</th>
                </tr>
            </thead>
            <tbody>
                @foreach($post->reports as $report)
                <tr>
                    <td>
                        <div style="font-weight: 500;">{{ $report->reporter?->full_name ?? 'Anonymous' }}</div>
                        <div style="font-size: 0.75rem; color: #888;">
                            @if($report->reporter?->username) @ {{ $report->reporter->username }} @endif
                        </div>
                        @if($report->reporter?->user)
                            <a href="{{ route('admin.users.show', $report->reporter->user) }}" style="font-size: 0.6875rem; text-decoration: none; color: #3b82f6;"><i class="fa-solid fa-user"></i> View Profile</a>
                        @endif
                    </td>
                    <td style="font-size: 0.875rem; color: #374151; white-space: pre-wrap; line-height: 1.4;">{{ $report->reason }}</td>
                    <td style="font-size: 0.75rem; color: #888; text-align: right;" title="{{ $report->created_at->format('Y-m-d H:i:s') }}">
                        {{ $report->created_at->diffForHumans() }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div style="text-align: center; color: #9ca3af; padding: 2rem 1rem; font-size: 0.875rem;">
            <i class="fa-solid fa-circle-check" style="font-size: 2rem; color: #10b981; margin-bottom: 0.5rem; display: block;"></i>
            No reports logged for this post.
        </div>
    @endif
</div>

{{-- Moderation Actions Card --}}
@canPermission('posts.moderate')
<div class="card">
    <div class="card-header">
        <h3>Moderation Actions</h3>
    </div>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        @if($post->is_active)
            <form method="POST" action="{{ route('admin.moderation.posts.deactivate', $post) }}">
                @csrf
                <button type="submit" class="btn-reject" style="padding: 0.6rem 1.25rem; font-weight: 500;" onclick="return confirm('Are you sure you want to deactivate this post? It will be hidden from all users.')">
                    <i class="fa-solid fa-ban"></i> Deactivate Post
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.moderation.posts.activate', $post) }}">
                @csrf
                <button type="submit" class="btn-approve" style="padding: 0.6rem 1.25rem; font-weight: 500;" onclick="return confirm('Are you sure you want to reactivate this post? It will be visible to all users again.')">
                    <i class="fa-solid fa-check"></i> Reactivate & Activate Post
                </button>
            </form>
        @endif
    </div>
</div>
@endcanPermission
@endsection
