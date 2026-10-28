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

@section('page-title', 'Subscription Plans')

@php
use Illuminate\Support\Str;
@endphp

@section('content')
<div class="card">
    <div class="card-header">
        <h3>All Plans</h3>
        {{-- <div class="card-header-actions">
            <a href="{{ route('subscription.create') }}" class="btn-primary"><i class="fa-solid fa-plus"></i> Add Plan</a>
        </div> --}}
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem;">
        @foreach($plans as $index => $plan)
        @php $sl = $plans->count() - $index; @endphp
        <div class="plan-card {{ $plan->is_popular ? 'popular' : '' }}" style="text-align: center;">
            @if($plan->is_popular)
            <div class="popular-badge">Most Popular</div>
            @endif
            <div class="plan-name">{{ $plan->name }}</div>
            <div class="plan-price">
                @if($plan->price > 0)
                    £{{ number_format($plan->price, 2) }} <span>/month</span>
                @else
                    Free
                @endif
            </div>
            <div class="plan-desc">{{ $plan->description ?? '' }}</div>
            <div style="display: flex; align-items: center; gap: 0.5rem; justify-content: center;">
                <x-status-badge :status="$plan->status" />
                <form method="POST" action="{{ route('subscription.toggleStatus', $plan) }}" style="display: inline;">
                    @csrf
                    @method('PATCH')
                    <label class="toggle-switch" style="margin-bottom: 0;">
                        <input type="checkbox" style="cursor:pointer;" {{ $plan->status === 'active' ? 'checked' : '' }} onclick="var cb=this; cb.disabled=true; setTimeout(function(){ cb.form.submit(); }, 50);" />
                        <span class="toggle-slider"></span>
                    </label>
                </form>
            </div>
            <ul class="plan-features">
                @foreach($plan->features ?? [] as $feature)
                <li><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                @endforeach
            </ul>
            <div style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
                <a href="{{ route('subscription.show', $plan) }}" class="btn-view-icon" title="View"><i class="fa-solid fa-eye"></i></a>
                <a href="{{ route('subscription.edit', $plan) }}" class="btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>

                {{-- subscription delete start --}}
                {{-- <button onclick="confirmDelete('subscription-delete-{{ $plan->id }}')" class="btn-delete" title="Delete"><i class="fa-solid fa-trash"></i></button>
                <form id="subscription-delete-{{ $plan->id }}" action="{{ route('subscription.destroy', $plan) }}" method="POST" style="display: none;">
                    @csrf
                    @method('DELETE')
                </form> --}}
                {{-- subscription delete end --}}
            </div>
        </div>
        @endforeach
    </div>
    @if($plans->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No plans yet.</p>
    @endif
</div>
    <script>
        @if(session('success'))
            toastr.success("{{ session('success') }}");
        @endif
    </script>
@endsection
