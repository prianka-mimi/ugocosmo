@extends('layouts.dashboard')

@section('page-title', $page ? 'Edit Page' : 'Create Page')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>{{ $page ? 'Edit Page' : 'New Page' }}</h3>
        <a href="{{ route('page.index') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.35rem;"></i> Back</a>
    </div>
    <form method="POST" action="{{ $page ? route('page.update', $page) : route('page.store') }}">
        @csrf
        @if($page)
            @method('PUT')
        @endif
        <div class="form-group">
            <label for="sort_order">Sort Order</label>
            <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $page->sort_order ?? 0) }}"/>
        </div>
        <div class="form-group" style="display: flex; align-items: center; gap: 0.75rem;">
            <label class="toggle-switch" style="margin-bottom: 0;">
                <input type="checkbox" name="status" value="active" {{ old('status', $page->status ?? 'active') != 'inactive' ? 'checked' : '' }} />
                <span class="toggle-slider"></span>
            </label>
            <span style="font-size: 0.8125rem; font-weight: 500; color: #333;">Active</span>
        </div>
        <div class="form-group">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="{{ old('title', $page->title ?? '') }}" required />
        </div>
        <div class="form-group">
            <label for="slug">Slug (URL)</label>
            <input type="text" id="slug" name="slug" value="{{ old('slug', $page->slug ?? '') }}" required />
        </div>
        <div class="form-group">
            <label for="content">Content (HTML)</label>
            <textarea id="content" name="content" class="rich-editor" required>{{ old('content', $page->content ?? '') }}</textarea>
        </div>
        <div style="text-align: right;">
            <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem;">{{ $page ? 'Update Page' : 'Create Page' }}</button>
        </div>
    </form>
</div>
@endsection