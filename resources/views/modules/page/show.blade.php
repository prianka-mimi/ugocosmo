@extends('layouts.dashboard')

@section('page-title', 'Page Details')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Page Details</h3>
        <div class="card-header-actions">
            <a href="{{ route('page.index') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.35rem;"></i> Back</a>
            <a href="{{ route('page.edit', $page) }}" class="btn-edit"><i class="fa-solid fa-pen"></i> Edit</a>
        </div>
    </div>
    <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Title</label>
            <p style="font-size: 1rem; font-weight: 600; color: #1b1b18; margin: 0;">{{ $page->title }}</p>
        </div>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Slug</label>
            <p style="font-size: 0.9375rem; color: #333; margin: 0;"><code style="background: #f5f5f5; padding: 0.15rem 0.4rem; border-radius: 0.25rem; font-size: 0.875rem;">/{{ $page->slug }}</code></p>
        </div>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Content</label>
            <div style="font-size: 0.9375rem; color: #333; margin: 0; line-height: 1.7;">{!! $page->content !!}</div>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Status</label>
            <x-status-badge :status="$page->status" />
        </div>
    </div>
</div>
@endsection
