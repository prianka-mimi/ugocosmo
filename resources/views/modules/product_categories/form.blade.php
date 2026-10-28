@extends('layouts.dashboard')

<style>
.toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
.toggle-slider { position: absolute; cursor: pointer; inset: 0; background: #d1d5db; border-radius: 24px; transition: 0.3s; }
.toggle-slider::before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.3s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.toggle-switch input:checked + .toggle-slider { background: #22c55e; }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
.toggle-switch input:disabled + .toggle-slider { opacity: 0.5; cursor: not-allowed; }
</style>

@section('page-title', $category ? 'Edit Product Category' : 'Create Product Category')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>{{ $category ? 'Edit Product Category' : 'New Product Category' }}</h3>
        <a href="{{ route('product-categories.index') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.35rem;"></i> Back</a>
    </div>
    <form method="POST" action="{{ $category ? route('product-categories.update', $category) : route('product-categories.store') }}">
        @csrf
        @if($category)
            @method('PUT')
        @endif
        
        <div class="form-group" style="display: flex; align-items: center; gap: 0.75rem;">
            <label class="toggle-switch" style="margin-bottom: 0;">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $category ? $category->is_active : true) ? 'checked' : '' }} />
                <span class="toggle-slider"></span>
            </label>
            <span style="font-size: 0.8125rem; font-weight: 500; color: #333;">Active</span>
        </div>

        <div class="form-group">
            <label for="name">Category Name</label>
            <input type="text" id="name" name="name" value="{{ old('name', $category->name ?? '') }}" required />
            @error('name')
                <span style="color: #ef4444; font-size: 0.8125rem; margin-top: 0.25rem; display: block;">{{ $message }}</span>
            @enderror
        </div>

        <div class="form-group">
            <label for="product_type">Product Type</label>
            <select id="product_type" name="product_type" required style="width: 100%; height: 2.5rem; padding: 0 0.75rem; border: 1px solid #ddd; border-radius: 0.375rem; background-color: #fff; font-size: 0.875rem;">
                <option value="physical" {{ old('product_type', $category->product_type ?? 'physical') === 'physical' ? 'selected' : '' }}>Physical Product</option>
                <option value="digital" {{ old('product_type', $category->product_type ?? '') === 'digital' ? 'selected' : '' }}>Digital Product</option>
            </select>
            @error('product_type')
                <span style="color: #ef4444; font-size: 0.8125rem; margin-top: 0.25rem; display: block;">{{ $message }}</span>
            @enderror
        </div>

        <div style="text-align: right; margin-top: 1.5rem;">
            <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem;">{{ $category ? 'Update Category' : 'Create Category' }}</button>
        </div>
    </form>
</div>
@endsection
