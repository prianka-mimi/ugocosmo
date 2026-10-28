@extends('layouts.dashboard')

@section('page-title', 'Product Details - #' . $product->id)

@section('content')
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-box" style="color: #0d9488; margin-right: 0.5rem;"></i> Product Moderation Details</h3>
        <div class="card-header-actions">
            <a href="{{ route('admin.moderation.products') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #f3f4f6; color: #374151; font-weight: 500; font-size: 0.875rem;"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    {{-- Seller Info Header --}}
    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; padding-bottom: 1.5rem;">
        @if($product->seller?->avatar)
            <img src="{{ asset('storage/' . $product->seller->avatar) }}" alt="" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;"/>
        @else
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #f3f4f6; display: flex; align-items: center; justify-content: center; border: 2px solid #e5e7eb;">
                <i class="fa-solid fa-user" style="font-size: 1.25rem; color: #9ca3af;"></i>
            </div>
        @endif
        <div>
            <div style="font-weight: 600; font-size: 1.05rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>{{ $product->seller?->full_name ?? 'Unknown Seller' }}</span>
                @if($product->seller?->user)
                    <a href="{{ route('admin.users.show', $product->seller->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; font-weight: normal;"><i class="fa-solid fa-external-link"></i> View Seller Profile</a>
                @endif
            </div>
            <div style="font-size: 0.8125rem; color: #6b7280;">
                @if($product->seller?->username) @ {{ $product->seller->username }} &bull; @endif 
                {{ $product->seller?->email ?? ($product->seller?->user?->email ?? 'No Email') }}
            </div>
            <div style="display: flex; gap: 0.5rem; margin-top: 0.35rem; align-items: center;">
                @if($product->is_active)
                    <span class="badge" style="background: #f0fdf4; color: #166534; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Active</span>
                @else
                    <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Deactivated</span>
                @endif
                <span style="font-size: 0.75rem; color: #9ca3af;">Created: {{ $product->created_at->format('d M Y, h:i A') }}</span>
            </div>
        </div>
    </div>

    {{-- Product Core Info --}}
    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
        @if($product->image_url)
            <div style="max-width: 400px; border-radius: 0.5rem; overflow: hidden; border: 1px solid #e5e7eb;">
                <img src="{{ asset('storage/' . $product->image_url) }}" alt="{{ $product->name }}" style="width: 100%; height: auto; object-fit: cover;"/>
            </div>
        @endif
        
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #1f2937; margin: 0 0 0.25rem 0;">{{ $product->name }}</h2>
                <div style="font-size: 0.875rem; color: #4b5563; font-weight: 500;">Category: <span style="text-transform: capitalize; color: #111827;">{{ $product->category }}</span></div>
            </div>

            <div style="font-size: 1.25rem; font-weight: 700; color: #0d9488; margin-top: 0.5rem;">
                Price: £{{ number_format($product->price ?? 0, 2) }}
            </div>

            <div style="background: #f9fafb; padding: 1rem; border-radius: 0.5rem; border: 1px solid #f3f4f6; margin-top: 0.5rem;">
                <div style="font-weight: 600; font-size: 0.75rem; color: #9ca3af; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Product Description</div>
                <div style="font-size: 0.875rem; line-height: 1.6; color: #374151; white-space: pre-wrap;">{{ $product->description ?? 'No description provided.' }}</div>
            </div>
        </div>
    </div>

    {{-- Moderation Controls --}}
    <div style="border-top: 1px solid #f3f4f6; padding-top: 1.5rem; display: flex; gap: 0.75rem;">
        @if($product->is_active)
            <form method="POST" action="{{ route('admin.moderation.products.deactivate', $product) }}">
                @csrf
                <button type="submit" class="btn-reject" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;" onclick="return confirm('Deactivate this product?')">
                    <i class="fa-solid fa-ban"></i> Deactivate Product
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.moderation.products.activate', $product) }}">
                @csrf
                <button type="submit" class="btn-approve" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;">
                    <i class="fa-solid fa-check"></i> Activate Product
                </button>
            </form>
        @endif
    </div>
</div>
@endsection
