@extends('layouts.dashboard')

@section('page-title', 'Service Category Details')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Service Category Details</h3>
        <div class="card-header-actions">
            <a href="{{ route('service-categories.index') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.35rem;"></i> Back</a>
            <a href="{{ route('service-categories.edit', $category) }}" class="btn-edit"><i class="fa-solid fa-pen"></i> Edit</a>
        </div>
    </div>
    <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Category Name</label>
            <p style="font-size: 1rem; font-weight: 600; color: #1b1b18; margin: 0;">{{ $category->name }}</p>
        </div>
        <div style="background: #fafafa; padding: 1rem; border-radius: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #999; display: block; margin-bottom: 0.35rem;">Status</label>
            <span class="badge {{ $category->is_active ? 'badge-free' : 'badge-popular' }}" style="background: {{ $category->is_active ? '#f0fdf4' : '#fef2f2' }}; color: {{ $category->is_active ? '#166534' : '#b91c1c' }};">
                {{ $category->is_active ? 'Active' : 'Inactive' }}
            </span>
        </div>
    </div>
</div>
@endsection
