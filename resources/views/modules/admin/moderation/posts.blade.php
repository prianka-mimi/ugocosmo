@extends('layouts.dashboard')

@section('page-title', 'Moderate Posts')

@section('content')
<div style="display: flex; gap: 0.5rem; border-bottom: 2px solid #e5e7eb; margin-bottom: 1.5rem; padding-bottom: 2px; overflow-x: auto; font-family: 'Inter', sans-serif;">
    <a href="{{ route('admin.moderation.posts') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.posts*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.posts*') ? '#0d9488' : '#6b7280' }};">Posts</a>
    <a href="{{ route('admin.moderation.products') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.products*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.products*') ? '#0d9488' : '#6b7280' }};">Products</a>
    <a href="{{ route('admin.moderation.services') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.services*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.services*') ? '#0d9488' : '#6b7280' }};">Services</a>
    <a href="{{ route('admin.moderation.jobs') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.jobs*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.jobs*') ? '#0d9488' : '#6b7280' }};">Jobs</a>
    <a href="{{ route('admin.moderation.events') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.events*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.events*') ? '#0d9488' : '#6b7280' }};">Events</a>
    <a href="{{ route('admin.moderation.bookings') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.bookings*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.bookings*') ? '#0d9488' : '#6b7280' }};">Bookings</a>
    <a href="{{ route('admin.moderation.job-applications') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.job-applications*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.job-applications*') ? '#0d9488' : '#6b7280' }};">Job Applications</a>
    <a href="{{ route('admin.moderation.tickets') }}" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; border-bottom: 2px solid {{ request()->routeIs('admin.moderation.tickets*') ? '#0d9488' : 'transparent' }}; color: {{ request()->routeIs('admin.moderation.tickets*') ? '#0d9488' : '#6b7280' }};">Tickets</a>
</div>

<div class="card">
    <div class="card-header">
        <h3>All Posts</h3>
        <form method="GET" action="{{ route('admin.moderation.posts') }}" style="display: flex; gap: 0.5rem; align-items: center;">
            <select name="is_active" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff;">
                <option value="">All Status</option>
                <option value="1" {{ request('is_active') === '1' ? 'selected' : '' }}>Active</option>
                <option value="0" {{ request('is_active') === '0' ? 'selected' : '' }}>Deactivated</option>
            </select>
            <div class="search-box">
                <i class="fa-solid fa-search"></i>
                <input type="text" name="search" placeholder="Search content..." value="{{ request('search') }}" onchange="this.form.submit()" />
            </div>
        </form>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width: 50px;">ID</th>
                <th>Content</th>
                <th>Author</th>
                <th>Reports</th>
                <th>Status</th>
                <th>Date</th>
                <th style="width: 160px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($posts as $post)
            <tr>
                <td>{{ $post->id }}</td>
                <td>
                    <div style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.8125rem;">
                        {{ \Illuminate\Support\Str::limit(strip_tags($post->content ?? $post->body), 60) }}
                    </div>
                </td>
                <td>{{ $post->author?->full_name ?? 'Unknown' }}</td>
                <td>
                    @if($post->reports && $post->reports->isNotEmpty())
                        <div style="position: relative;">
                            <span class="badge" style="background: #fef2f2; color: #b91c1c; cursor: pointer;"
                                  onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'block' ? 'none' : 'block'">
                                {{ $post->reports->count() }} report{{ $post->reports->count() > 1 ? 's' : '' }}
                            </span>
                            <div style="display: none; position: absolute; z-index: 10; background: #fff; border: 1px solid #ddd; border-radius: 0.375rem; padding: 0.75rem; min-width: 280px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 0.75rem;">
                                @foreach($post->reports as $report)
                                    <div style="padding: 0.5rem 0; {{ !$loop->last ? 'border-bottom: 1px solid #eee;' : '' }}">
                                        <strong>{{ $report->reporter?->full_name ?? 'Anonymous' }}</strong>
                                        <span style="color: #999; display: block; margin-top: 0.25rem;">{{ $report->reason }}</span>
                                        <span style="color: #aaa; font-size: 0.625rem; display: block; margin-top: 0.125rem;">
                                            {{ $report->created_at->diffForHumans() }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <span style="color: #999; font-size: 0.75rem;">No reports</span>
                    @endif
                </td>
                <td>
                    @if($post->is_active)
                        <span class="badge" style="background: #f0fdf4; color: #166534;">Active</span>
                    @else
                        <span class="badge" style="background: #fef2f2; color: #b91c1c;">Deactivated</span>
                    @endif
                </td>
                <td style="font-size: 0.75rem; color: #888;">{{ $post->created_at->format('d M Y') }}</td>
                <td style="text-align: right; white-space: nowrap;">
                    <a href="{{ route('admin.moderation.posts.show', $post) }}" class="btn-edit" style="text-decoration: none; display: inline-block; margin-right: 0.25rem;"><i class="fa-solid fa-eye"></i> Details</a>
                    @if($post->is_active)
                    <form method="POST" action="{{ route('admin.moderation.posts.deactivate', $post) }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn-reject" onclick="return confirm('Deactivate this post?')"><i class="fa-solid fa-ban"></i> Deactivate</button>
                    </form>
                    @else
                    <form method="POST" action="{{ route('admin.moderation.posts.activate', $post) }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn-approve"><i class="fa-solid fa-check"></i> Activate</button>
                    </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($posts->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No posts found.</p>
    @endif
</div>
@endsection
