@extends('layouts.dashboard')

@section('page-title', 'Dispute Management')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>All Disputes</h3>
        <form method="GET" action="{{ route('admin.disputes.index') }}" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <div class="search-box">
                <i class="fa-solid fa-search"></i>
                <input type="text" name="search" placeholder="Search order, reason..." value="{{ request('search') }}" onchange="this.form.submit()" />
            </div>
            <select name="status" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff;">
                <option value="">All Statuses</option>
                @foreach($statuses as $s)
                    <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            @if(request()->anyFilled(['search', 'status']))
                <a href="{{ route('admin.disputes.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.4rem 0.75rem; font-size: 0.875rem;"><i class="fa-solid fa-rotate-left"></i></a>
            @endif
        </form>
    </div>
    <table id="dataTable">
        <thead>
            <tr>
                <th style="width: 60px;">ID</th>
                <th>Order</th>
                <th>Raised By</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Date</th>
                <th style="width: 100px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($disputes as $dispute)
            <tr>
                <td>#{{ $dispute->id }}</td>
                <td><a href="{{ route('admin.orders.show', $dispute->order) }}" style="color: var(--primary); text-decoration: none;">#{{ $dispute->order_id }}</a></td>
                <td style="font-size: 0.8125rem;">{{ $dispute->raisedBy?->full_name ?? 'N/A' }}</td>
                <td style="font-size: 0.8125rem; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $dispute->reason }}</td>
                <td>
                    @php
                        $colors = ['pending' => '#f59e0b', 'investigating' => '#6366f1', 'resolved' => '#22c55e', 'dismissed' => '#888'];
                    @endphp
                    <span class="badge" style="background: {{ ($colors[$dispute->status] ?? '#555') }}15; color: {{ $colors[$dispute->status] ?? '#555' }}; text-transform: capitalize;">{{ str_replace('_', ' ', $dispute->status) }}</span>
                </td>
                <td style="font-size: 0.75rem; color: #888;">{{ $dispute->created_at->format('M d, Y') }}</td>
                <td style="text-align: right;">
                    <a href="{{ route('admin.disputes.show', $dispute) }}" class="btn-view-icon" title="View Details"><i class="fa-solid fa-eye"></i></a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($disputes->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No disputes found.</p>
    @endif
</div>
@endsection
