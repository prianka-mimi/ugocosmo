@extends('layouts.dashboard')

@section('page-title', 'App Settings')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>App Settings</h3>
    </div>

    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
        @csrf
        <div class="form-group">
            <label for="tab_name">Tab Name (Browser Tab Title)</label>
            <input type="text" id="tab_name" name="tab_name" value="{{ old('tab_name', $settings['tab_name']) }}" placeholder="My App" />
        </div>
        <div class="form-group">
            <label for="primary_color">Primary Color (Dashboard Theme)</label>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <input type="color" id="primary_color" name="primary_color" value="{{ old('primary_color', $settings['primary_color']) }}" style="width: 50px; height: 38px; padding: 2px; border: 1px solid #ddd; border-radius: 0.5rem; cursor: pointer;" onchange="document.getElementById('colorPreview').style.background = this.value; document.getElementById('colorHex').value = this.value;"/>
                <input type="text" id="colorHex" value="{{ old('primary_color', $settings['primary_color']) }}" style="width: 120px;" readonly />
                <div id="colorPreview" class="color-preview" style="width: 60px; height: 38px; background: {{ old('primary_color', $settings['primary_color']) }}; border-radius: 0.5rem; border: 1px solid #ddd;"></div>
            </div>
            <small style="color: #888; font-size: 0.75rem;">Click the color picker or enter a hex code (e.g. #E87450). Applies to sidebar, buttons, and highlights.</small>
        </div>
        <div class="form-group">
            <label>Logo Image</label>
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem;">
                @if($settings['logo'])
                    <img src="{{ asset('storage/' . $settings['logo']) }}" alt="Current Logo" style="max-height: 50px; max-width: 150px; object-fit: contain; border: 1px solid #e5e5e5; border-radius: 0.375rem; padding: 0.25rem;"/>
                @else
                    <img src="{{ asset('img/logo.png') }}" alt="Default Logo" style="max-height: 50px; max-width: 150px; object-fit: contain; border: 1px solid #e5e5e5; border-radius: 0.375rem; padding: 0.25rem;"/>
                @endif
            </div>
            <input type="file" name="logo" class="dropify" data-height="80" data-max-file-size="5M" data-allowed-file-extensions="jpg jpeg png gif svg" data-default-file="@if($settings['logo']){{ asset('storage/' . $settings['logo']) }}@endif"/>
            <small style="color: #888; font-size: 0.75rem;">Recommended: PNG or SVG, max 2MB. Upload a new file to replace the current logo.</small>
        </div>
        <div class="form-group">
            <label>Favicon Image</label>
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem;">
                @if($settings['favicon'])
                    <img src="{{ asset('storage/' . $settings['favicon']) }}" alt="Current Favicon" style="max-height: 32px; max-width: 32px; object-fit: contain; border: 1px solid #e5e5e5; border-radius: 0.375rem; padding: 0.25rem;"/>
                @else
                    <img src="{{ asset('img/logo.png') }}" alt="Default Favicon" style="max-height: 32px; max-width: 32px; object-fit: contain; border: 1px solid #e5e5e5; border-radius: 0.375rem; padding: 0.25rem;"/>
                @endif
            </div>
            <input type="file" name="favicon" class="dropify" data-height="60" data-max-file-size="1M" data-allowed-file-extensions="jpg jpeg png gif ico" data-default-file="@if($settings['favicon']){{ asset('storage/' . $settings['favicon']) }}@endif"/>
            <small style="color: #888; font-size: 0.75rem;">Recommended: ICO or PNG (32x32), max 1MB. Appears in browser tab.</small>
        </div>
        <div style="text-align: right; margin-top: 1.5rem;">
            <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem;">
                <i class="fa-solid fa-check"></i> Save Settings
            </button>
        </div>
    </form>
</div>
    <script>
        @if(session('success'))
            toastr.success("{{ session('success') }}");
        @endif
    </script>
@endsection
