@extends('layouts.dashboard')

@section('page-title', 'Livestream Gifting & Gamification Settings')

@section('content')
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h2 style="font-size:1.25rem;font-weight:700;color:#1b1b18;margin:0;">Livestream Gifting & Gamification Settings</h2>
        <p style="font-size:0.8125rem;color:#888;margin:0.25rem 0 0;">Manage gift icons, tip amounts, and gamification price presets for live streams</p>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success" style="padding: 0.75rem 1.25rem; background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; border-radius: 0.5rem; margin-bottom: 1rem;">
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger" style="padding: 0.75rem 1.25rem; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 0.5rem; margin-bottom: 1rem;">
        {{ session('error') }}
    </div>
@endif

{{-- Tabs --}}
<div style="display:flex; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 1px solid #e5e5e5; padding-bottom: 2px;">
    <a href="{{ route('admin.livestream-gifting.index', ['tab' => 'gifts']) }}" 
       style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; border-bottom: 3px solid {{ $tab === 'gifts' ? 'var(--primary, #0d9488)' : 'transparent' }}; color: {{ $tab === 'gifts' ? 'var(--primary, #0d9488)' : '#555' }}; transition: all 0.2s;">
        <i class="fa-solid fa-gift"></i> Gift Items
    </a>
    <a href="{{ route('admin.livestream-gifting.index', ['tab' => 'presets']) }}" 
       style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; border-bottom: 3px solid {{ $tab === 'presets' ? 'var(--primary, #0d9488)' : 'transparent' }}; color: {{ $tab === 'presets' ? 'var(--primary, #0d9488)' : '#555' }}; transition: all 0.2s;">
        <i class="fa-solid fa-dollar-sign"></i> Tip Amount Presets
    </a>
    <a href="{{ route('admin.livestream-gifting.index', ['tab' => 'gamification']) }}" 
       style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; border-bottom: 3px solid {{ $tab === 'gamification' ? 'var(--primary, #0d9488)' : 'transparent' }}; color: {{ $tab === 'gamification' ? 'var(--primary, #0d9488)' : '#555' }}; transition: all 0.2s;">
        <i class="fa-solid fa-gamepad"></i> Gamification Configuration
    </a>
</div>

{{-- Action Button --}}
<div style="margin-bottom: 1.25rem; display: flex; justify-content: flex-end;">
    <a href="{{ route('admin.livestream-gifting.create', ['tab' => $tab]) }}" class="btn-primary" style="padding: 0.5rem 1rem; text-decoration: none; border-radius: 0.5rem; display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.8125rem;">
        <i class="fa-solid fa-plus"></i> New {{ $tab === 'gamification' ? 'Price Preset' : ($tab === 'presets' ? 'Tip Preset' : 'Gift Item') }}
    </a>
</div>

{{-- Data Table --}}
<div class="card">
    @if($tab === 'gamification')
        @if($gamificationPresets->isEmpty())
            <div style="text-align: center; padding: 3rem 2rem; color: #888;">
                <i class="fa-solid fa-gamepad" style="font-size: 2.5rem; margin-bottom: 0.75rem; display: block; color: #ccc;"></i>
                No gamification price presets configured yet. Click "New Price Preset" above to create one.
            </div>
        @else
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #eee;">
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">#</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">Amount</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600; text-align: center;">Status</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($gamificationPresets as $item)
                    <tr style="border-bottom: 1px solid #f0f0f0; font-size: 0.875rem;">
                        <td style="padding: 1rem 0.75rem; color: #999;">{{ $item->id }}</td>
                        <td style="padding: 1rem 0.75rem; font-weight: 700; color: #111;">${{ number_format((float) $item->amount, 2) }}</td>
                        <td style="padding: 1rem 0.75rem; text-align: center;">
                            @if($item->is_active)
                                <span style="background: #ecfdf5; color: #166534; padding: 0.25rem 0.6rem; border-radius: 9999px; font-weight: 600; font-size: 0.75rem;">Active</span>
                            @else
                                <span style="background: #fef2f2; color: #b91c1c; padding: 0.25rem 0.6rem; border-radius: 9999px; font-weight: 600; font-size: 0.75rem;">Inactive</span>
                            @endif
                        </td>
                        <td style="padding: 1rem 0.75rem; text-align: right; white-space: nowrap;">
                            <div style="display: inline-flex; gap: 0.35rem;">
                                <form method="POST" action="{{ route('admin.livestream-gifting.toggle', ['id' => $item->id, 'tab' => 'gamification']) }}" style="margin: 0;">
                                    @csrf
                                    <button type="submit" class="btn-cancel" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; border: 1px solid #ccc; background: transparent; cursor: pointer; border-radius: 0.375rem;" title="Toggle active status">
                                        <i class="fa-solid {{ $item->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i> {{ $item->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                                <a href="{{ route('admin.livestream-gifting.edit', ['id' => $item->id, 'tab' => 'gamification']) }}" class="btn-edit" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; text-decoration: none; border-radius: 0.375rem; background: #f3f4f6; color: #374151; display: inline-flex; align-items: center; gap: 0.25rem;">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </a>
                                <form method="POST" action="{{ route('admin.livestream-gifting.destroy', ['id' => $item->id, 'tab' => 'gamification']) }}" onsubmit="return confirm('Are you sure you want to delete this preset?');" style="margin: 0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-delete" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 0.375rem; cursor: pointer;">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @elseif($tab === 'presets')
        @if($presets->isEmpty())
            <div style="text-align: center; padding: 3rem 2rem; color: #888;">
                <i class="fa-solid fa-dollar-sign" style="font-size: 2.5rem; margin-bottom: 0.75rem; display: block; color: #ccc;"></i>
                No tip presets configured yet. Click "New Tip Preset" above to create one.
            </div>
        @else
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #eee;">
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">#</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">Amount</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600; text-align: center;">Status</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($presets as $preset)
                    <tr style="border-bottom: 1px solid #f0f0f0; font-size: 0.875rem;">
                        <td style="padding: 1rem 0.75rem; color: #999;">{{ $preset->id }}</td>
                        <td style="padding: 1rem 0.75rem; font-weight: 700; color: #111;">${{ number_format((float) $preset->amount, 2) }}</td>
                        <td style="padding: 1rem 0.75rem; text-align: center;">
                            @if($preset->is_active)
                                <span style="background: #ecfdf5; color: #166534; padding: 0.25rem 0.6rem; border-radius: 9999px; font-weight: 600; font-size: 0.75rem;">Active</span>
                            @else
                                <span style="background: #fef2f2; color: #b91c1c; padding: 0.25rem 0.6rem; border-radius: 9999px; font-weight: 600; font-size: 0.75rem;">Inactive</span>
                            @endif
                        </td>
                        <td style="padding: 1rem 0.75rem; text-align: right; white-space: nowrap;">
                            <div style="display: inline-flex; gap: 0.35rem;">
                                <form method="POST" action="{{ route('admin.livestream-gifting.toggle', ['id' => $preset->id, 'tab' => 'presets']) }}" style="margin: 0;">
                                    @csrf
                                    <button type="submit" class="btn-cancel" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; border: 1px solid #ccc; background: transparent; cursor: pointer; border-radius: 0.375rem;" title="Toggle active status">
                                        <i class="fa-solid {{ $preset->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i> {{ $preset->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                                <a href="{{ route('admin.livestream-gifting.edit', ['id' => $preset->id, 'tab' => 'presets']) }}" class="btn-edit" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; text-decoration: none; border-radius: 0.375rem; background: #f3f4f6; color: #374151; display: inline-flex; align-items: center; gap: 0.25rem;">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </a>
                                <form method="POST" action="{{ route('admin.livestream-gifting.destroy', ['id' => $preset->id, 'tab' => 'presets']) }}" onsubmit="return confirm('Are you sure you want to delete this preset?');" style="margin: 0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-delete" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 0.375rem; cursor: pointer;">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @else
        @if($gifts->isEmpty())
            <div style="text-align: center; padding: 3rem 2rem; color: #888;">
                <i class="fa-solid fa-gift" style="font-size: 2.5rem; margin-bottom: 0.75rem; display: block; color: #ccc;"></i>
                No gift items configured yet. Click "New Gift Item" above to create one.
            </div>
        @else
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #eee;">
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">#</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">Icon</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">Title</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600; text-align: center;">Price (Coins)</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600; text-align: center;">Status</th>
                        <th style="padding: 0.75rem; color: #555; font-size: 0.75rem; text-transform: uppercase; font-weight: 600; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($gifts as $gift)
                    <tr style="border-bottom: 1px solid #f0f0f0; font-size: 0.875rem;">
                        <td style="padding: 1rem 0.75rem; color: #999;">{{ $gift->id }}</td>
                        <td style="padding: 0.5rem 0.75rem; width: 60px;">
                            @if($gift->icon_url)
                                <img src="{{ $gift->icon_url }}" alt="{{ $gift->title }}" style="width: 36px; height: 36px; border-radius: 0.375rem; object-fit: contain; background: #f9fafb; border: 1px solid #eee;">
                            @else
                                <span style="color: #ccc; font-style: italic;">No icon</span>
                            @endif
                        </td>
                        <td style="padding: 1rem 0.75rem; font-weight: 600; color: #111;">{{ $gift->title }}</td>
                        <td style="padding: 1rem 0.75rem; text-align: center; font-weight: 700; color: #0d9488;">{{ $gift->coin_cost }} <span style="font-size: 0.75rem; font-weight: 500; color: #888;">coins</span></td>
                        <td style="padding: 1rem 0.75rem; text-align: center;">
                            @if($gift->is_active)
                                <span style="background: #ecfdf5; color: #166534; padding: 0.25rem 0.6rem; border-radius: 9999px; font-weight: 600; font-size: 0.75rem;">Active</span>
                            @else
                                <span style="background: #fef2f2; color: #b91c1c; padding: 0.25rem 0.6rem; border-radius: 9999px; font-weight: 600; font-size: 0.75rem;">Inactive</span>
                            @endif
                        </td>
                        <td style="padding: 1rem 0.75rem; text-align: right; white-space: nowrap;">
                            <div style="display: inline-flex; gap: 0.35rem;">
                                <form method="POST" action="{{ route('admin.livestream-gifting.toggle', ['id' => $gift->id, 'tab' => 'gifts']) }}" style="margin: 0;">
                                    @csrf
                                    <button type="submit" class="btn-cancel" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; border: 1px solid #ccc; background: transparent; cursor: pointer; border-radius: 0.375rem;" title="Toggle active status">
                                        <i class="fa-solid {{ $gift->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i> {{ $gift->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                                <a href="{{ route('admin.livestream-gifting.edit', ['id' => $gift->id, 'tab' => 'gifts']) }}" class="btn-edit" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; text-decoration: none; border-radius: 0.375rem; background: #f3f4f6; color: #374151; display: inline-flex; align-items: center; gap: 0.25rem;">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </a>
                                <form method="POST" action="{{ route('admin.livestream-gifting.destroy', ['id' => $gift->id, 'tab' => 'gifts']) }}" onsubmit="return confirm('Are you sure you want to delete this gift?');" style="margin: 0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-delete" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 0.375rem; cursor: pointer;">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
</div>
@endsection
