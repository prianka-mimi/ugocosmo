@extends('layouts.dashboard')

@section('page-title', $faq ? 'Edit FAQ' : 'Create FAQ')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>{{ $faq ? 'Edit FAQ' : 'New FAQ' }}</h3>
        <a href="{{ route('faq.index') }}" class="btn-cancel" style="text-decoration: none;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.35rem;"></i> Back</a>
    </div>
    <form method="POST" action="{{ $faq ? route('faq.update', $faq) : route('faq.store') }}">
        @csrf
        @if($faq)
            @method('PUT')
        @endif
        <div class="form-group">
            <label for="sort_order">Sort Order</label>
            <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $faq->sort_order ?? 0) }}"/>
        </div>
        <div class="form-group" style="display: flex; align-items: center; gap: 0.75rem;">
            <label class="toggle-switch" style="margin-bottom: 0;">
                <input type="checkbox" name="status" value="active" {{ old('status', $faq->status ?? 'active') != 'inactive' ? 'checked' : '' }} />
                <span class="toggle-slider"></span>
            </label>
            <span style="font-size: 0.8125rem; font-weight: 500; color: #333;">Active</span>
        </div>
        <div class="form-group">
            <label for="question">Question</label>
            <input type="text" id="question" name="question" value="{{ old('question', $faq->question ?? '') }}" required />
        </div>
        <div class="form-group">
            <label for="answer">Answer</label>
            <textarea id="answer" name="answer" class="rich-editor" required>{{ old('answer', $faq->answer ?? '') }}</textarea>
        </div>
        <div style="text-align: right;">
            <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem;">{{ $faq ? 'Update FAQ' : 'Create FAQ' }}</button>
        </div>
    </form>
</div>
@endsection