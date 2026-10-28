@extends('layouts.dashboard')

@section('page-title', 'FAQ Details')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>FAQ Details</h3>
        <div class="card-header-actions">
            <a href="{{ route('faq.index') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.35rem;"></i> Back</a>
            <a href="{{ route('faq.edit', $faq) }}" class="btn-edit"><i class="fa-solid fa-pen"></i> Edit</a>
        </div>
    </div>
    <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Question</label>
            <p style="font-size: 1rem; font-weight: 600; color: #1b1b18; margin: 0;">{{ $faq->question }}</p>
        </div>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Answer</label>
            <p style="font-size: 0.9375rem; color: #333; margin: 0; line-height: 1.6;">{!! $faq->answer !!}</p>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Status</label>
            <x-status-badge :status="$faq->status" />
        </div>
    </div>
</div>
@endsection
