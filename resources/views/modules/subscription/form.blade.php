@extends('layouts.dashboard')

@section('page-title', $plan ? 'Edit Subscription Plan' : 'Create Subscription Plan')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>{{ $plan ? 'Edit Plan' : 'New Plan' }}</h3>
        <a href="{{ route('subscription.index') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.35rem;"></i> Back</a>
    </div>
    <form method="POST" action="{{ $plan ? route('subscription.update', $plan) : route('subscription.store') }}">
        @csrf
        @if($plan)
            @method('PUT')
        @endif
        <div class="form-group">
            <label for="sort_order">Sort Order</label>
            <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $plan->sort_order ?? 0) }}"/>
        </div>
        <div class="form-group" style="display: flex; align-items: center; gap: 0.75rem;">
            <label class="toggle-switch" style="margin-bottom: 0;">
                <input type="checkbox" name="status" value="active" {{ old('status', $plan->status ?? 'active') != 'inactive' ? 'checked' : '' }} />
                <span class="toggle-slider"></span>
            </label>
            <span style="font-size: 0.8125rem; font-weight: 500; color: #333;">Active</span>
        </div>
        <div class="form-group">
            <label for="name">Plan Name</label>
            <input type="text" id="name" name="name" value="{{ old('name', $plan->name ?? '') }}" required />
        </div>
        <div class="form-group">
            <label for="slug">Slug</label>
            <input type="text" id="slug" name="slug" value="{{ old('slug', $plan->slug ?? '') }}" required />
        </div>
        <div class="form-group">
            <label for="price">Price (£)</label>
            <input type="number" id="price" name="price" step="0.01" min="0" value="{{ old('price', $plan->price ?? 0) }}" />
        </div>
        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" class="rich-editor">{{ old('description', $plan->description ?? '') }}</textarea>
        </div>
        <div class="form-group checkbox-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="is_popular" value="1" {{ old('is_popular', $plan->is_popular ?? false) ? 'checked' : '' }} />
                Mark as Most Popular
            </label>
        </div>
        <div class="form-group">
            <label>Features (one per line)</label>
            <textarea name="features" class="rich-editor" style="min-height: 80px;">{{ old('features') ?: (is_array($plan->features ?? []) ? implode("\n", $plan->features) : '') }}</textarea>
        </div>
        <div style="text-align: right;">
            <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem;">{{ $plan ? 'Update Plan' : 'Create Plan' }}</button>
        </div>
    </form>
</div>
@endsection