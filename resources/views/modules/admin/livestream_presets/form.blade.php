@extends('layouts.dashboard')

@section('page-title', ($gamificationPreset || $preset || $gift) ? 'Edit Item' : 'New Item')

@section('content')
@php
    $isGamification = ($tab === 'gamification');
    $isPreset = ($tab === 'presets');
    $isAmountType = ($isPreset || $isGamification);
    $isEdit = ($gamificationPreset || $preset || $gift);
    
    $recordId = null;
    if ($gamificationPreset) {
        $recordId = $gamificationPreset->id;
    } elseif ($preset) {
        $recordId = $preset->id;
    } elseif ($gift) {
        $recordId = $gift->id;
    }

    $actionUrl = $isEdit
        ? route('admin.livestream-gifting.update', ['id' => $recordId, 'tab' => $tab])
        : route('admin.livestream-gifting.store', ['tab' => $tab]);
@endphp

<div class="page-header" style="margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;gap:0.5rem;">
        <a href="{{ route('admin.livestream-gifting.index', ['tab' => $tab]) }}" style="color: #6b7280; text-decoration: none; font-size: 1rem;"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
    <h2 style="font-size:1.25rem;font-weight:700;color:#1b1b18;margin:0.5rem 0 0;">
        {{ $isEdit ? 'Edit' : 'Create' }} {{ $isGamification ? 'Gamification Price Preset' : ($isPreset ? 'Tip Amount Preset' : 'Gift Item') }}
    </h2>
</div>

@if(session('error'))
    <div class="alert alert-danger" style="padding: 0.75rem 1.25rem; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 0.5rem; margin-bottom: 1rem;">
        {{ session('error') }}
    </div>
@endif

<div class="card" style="max-width: 600px;">
    <form method="POST" action="{{ $actionUrl }}" enctype="multipart/form-data">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        @if($isAmountType)
            {{-- Presets & Gamification Amount Form --}}
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label style="font-size: 0.8125rem; font-weight: 600; color: #374151; display: block; margin-bottom: 0.35rem;">Amount (USD)</label>
                <div style="position: relative;">
                    <span style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #6b7280; font-size: 0.875rem;">$</span>
                    @php
                        $currentAmount = '';
                        if ($gamificationPreset) {
                            $currentAmount = $gamificationPreset->amount;
                        } elseif ($preset) {
                            $currentAmount = $preset->amount;
                        }
                    @endphp
                    <input type="number" step="0.01" name="amount" value="{{ old('amount', $currentAmount) }}" placeholder="0.00" style="padding: 0.5rem 0.75rem 0.5rem 1.5rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.875rem; width: 100%; box-sizing: border-box; outline: none;" required>
                </div>
                @error('amount')
                    <div style="color: #dc2626; font-size: 0.75rem; margin-top: 0.25rem;">{{ $message }}</div>
                @enderror
            </div>
        @else
            {{-- Gifts Form --}}
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label style="font-size: 0.8125rem; font-weight: 600; color: #374151; display: block; margin-bottom: 0.35rem;">Gift Title</label>
                <input type="text" name="title" value="{{ old('title', $gift ? $gift->title : '') }}" placeholder="e.g. Heart, Diamond..." style="padding: 0.5rem 0.75rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.875rem; width: 100%; box-sizing: border-box; outline: none;" required>
                @error('title')
                    <div style="color: #dc2626; font-size: 0.75rem; margin-top: 0.25rem;">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label style="font-size: 0.8125rem; font-weight: 600; color: #374151; display: block; margin-bottom: 0.35rem;">Price (Coins)</label>
                <input type="number" name="coin_cost" value="{{ old('coin_cost', $gift ? $gift->coin_cost : '') }}" placeholder="e.g. 50" style="padding: 0.5rem 0.75rem; border: 1px solid #ddd; border-radius: 0.5rem; font-size: 0.875rem; width: 100%; box-sizing: border-box; outline: none;" required>
                @error('coin_cost')
                    <div style="color: #dc2626; font-size: 0.75rem; margin-top: 0.25rem;">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label style="font-size: 0.8125rem; font-weight: 600; color: #374151; display: block; margin-bottom: 0.35rem;">Icon Image File</label>
                <input type="file" name="icon_file" class="dropify" data-height="120" data-max-file-size="2M" data-allowed-file-extensions="jpg jpeg png gif svg webp" data-default-file="{{ $gift ? $gift->icon_url : '' }}" {{ $isEdit ? '' : 'required' }}>
                <p style="font-size: 0.75rem; color: #888; margin: 0.35rem 0 0;">Upload a clear SVG, PNG, or WebP graphic. Max 2MB.</p>
                @error('icon_file')
                    <div style="color: #dc2626; font-size: 0.75rem; margin-top: 0.25rem;">{{ $message }}</div>
                @enderror
            </div>
        @endif

        @php
            $currentIsActive = true;
            if ($gamificationPreset) {
                $currentIsActive = (bool) $gamificationPreset->is_active;
            } elseif ($preset) {
                $currentIsActive = (bool) $preset->is_active;
            } elseif ($gift) {
                $currentIsActive = (bool) $gift->is_active;
            }
        @endphp

        <div class="form-group" style="margin-bottom: 1.5rem;">
            <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.875rem; color: #374151; font-weight: 500;">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $currentIsActive) ? 'checked' : '' }} style="width: 1rem; height: 1rem; cursor: pointer;">
                Active & Visible to Viewers
            </label>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid #f3f4f6; padding-top: 1.25rem;">
            <a href="{{ route('admin.livestream-gifting.index', ['tab' => $tab]) }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.5rem; border: 1px solid #ccc; color: #374151; font-size: 0.8125rem; display: inline-flex; align-items: center;">Cancel</a>
            <button type="submit" class="btn-primary" style="padding: 0.5rem 1.25rem; border-radius: 0.5rem; font-size: 0.8125rem; border: none; cursor: pointer;">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </div>
    </form>
</div>
@endsection
