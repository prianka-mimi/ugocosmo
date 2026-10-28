@extends('layouts.dashboard')

@section('page-title', 'Verification Submissions')

@section('content')
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h2 style="font-size:1.25rem;font-weight:700;color:#1b1b18;margin:0;">Verification Submissions</h2>
        <p style="font-size:0.8125rem;color:#888;margin:0.25rem 0 0;">Review and manage user identity & document submissions</p>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;">{{ session('error') }}</div>
@endif

{{-- Filters --}}
<div class="card" style="margin-bottom:1.25rem;">
    <form method="GET" action="{{ route('admin.verifications.index') }}" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:180px;">
            <label style="font-size:0.75rem;font-weight:600;color:#555;display:block;margin-bottom:0.3rem;">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Name or business…" style="padding:0.45rem 0.75rem;border:1px solid #ddd;border-radius:0.5rem;font-size:0.875rem;width:100%;box-sizing:border-box;outline:none;">
        </div>
        <div class="form-group" style="margin:0;min-width:150px;">
            <label style="font-size:0.75rem;font-weight:600;color:#555;display:block;margin-bottom:0.3rem;">Status</label>
            <select name="status" style="padding:0.45rem 0.75rem;border:1px solid #ddd;border-radius:0.5rem;font-size:0.875rem;outline:none;">
                <option value="">All Statuses</option>
                <option value="payment_pending" {{ request('status') === 'payment_pending' ? 'selected' : '' }}>Payment Pending</option>
                <option value="pending"         {{ request('status') === 'pending'         ? 'selected' : '' }}>Pending Review</option>
                <option value="approved"        {{ request('status') === 'approved'        ? 'selected' : '' }}>Approved</option>
                <option value="rejected"        {{ request('status') === 'rejected'        ? 'selected' : '' }}>Rejected</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;min-width:180px;">
            <label style="font-size:0.75rem;font-weight:600;color:#555;display:block;margin-bottom:0.3rem;">Plan</label>
            <select name="subscription_plan_id" style="padding:0.45rem 0.75rem;border:1px solid #ddd;border-radius:0.5rem;font-size:0.875rem;outline:none;">
                <option value="">All Plans</option>
                @foreach($plans as $plan)
                    <option value="{{ $plan->id }}" {{ request('subscription_plan_id') == $plan->id ? 'selected' : '' }}>{{ $plan->name }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:0.5rem;">
            <button type="submit" class="btn-primary" style="padding:0.45rem 1rem;font-size:0.8125rem;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <a href="{{ route('admin.verifications.index') }}" class="btn-cancel" style="text-decoration:none;padding:0.45rem 0.75rem;font-size:0.8125rem;">
                <i class="fa-solid fa-rotate-left"></i>
            </a>
        </div>
    </form>
</div>

{{-- Table --}}
<div class="card">
    <table id="dataTable" data-hide-filter="1">
        <thead>
            <tr>
                <th>#</th>
                <th>User</th>
                <th>Plan</th>
                <th>Name / Business</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($submissions as $sub)
            <tr>
                <td style="color:#aaa;font-size:0.75rem;">{{ $sub->id }}</td>
                <td>
                    <div style="font-weight:600;font-size:0.8125rem;">{{ $sub->user?->user?->name ?? '—' }}</div>
                    <div style="color:#888;font-size:0.75rem;">{{ $sub->user?->user?->email ?? '' }}</div>
                </td>
                <td>
                    <span class="badge badge-free">{{ $sub->subscriptionPlan?->name ?? '—' }}</span>
                </td>
                <td>
                    <div style="font-size:0.8125rem;">{{ $sub->first_name }} {{ $sub->last_name }}</div>
                    @if($sub->business_name)
                        <div style="color:#888;font-size:0.75rem;">{{ $sub->business_name }}</div>
                    @endif
                </td>
                <td>
                    @if($sub->payment_status === 'completed')
                        <span class="badge" style="background:#f0fdf4;color:#166534;">Paid</span>
                    @else
                        <span class="badge" style="background:#fff7ed;color:#c2410c;">Unpaid</span>
                    @endif
                </td>
                <td>
                    @php
                        $statusMap = [
                            'payment_pending' => ['bg'=>'#fff7ed','color'=>'#c2410c','label'=>'Payment Pending'],
                            'pending'         => ['bg'=>'#eff6ff','color'=>'#1d4ed8','label'=>'Pending Review'],
                            'approved'        => ['bg'=>'#f0fdf4','color'=>'#166534','label'=>'Approved'],
                            'rejected'        => ['bg'=>'#fef2f2','color'=>'#b91c1c','label'=>'Rejected'],
                        ];
                        $s = $statusMap[$sub->status] ?? ['bg'=>'#f5f5f5','color'=>'#555','label'=>ucfirst($sub->status)];
                    @endphp
                    <span class="badge" style="background:{{ $s['bg'] }};color:{{ $s['color'] }};">{{ $s['label'] }}</span>
                </td>
                <td style="color:#888;font-size:0.75rem;">{{ $sub->created_at?->format('d M Y') }}</td>
                <td>
                    <a href="{{ route('admin.verifications.show', $sub) }}" class="btn-view-icon" title="View Details">
                        <i class="fa-solid fa-eye"></i>
                    </a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Pagination --}}
    @if($submissions->hasPages())
    <div style="margin-top:1rem;">
        {{ $submissions->links() }}
    </div>
    @endif
</div>
@endsection
