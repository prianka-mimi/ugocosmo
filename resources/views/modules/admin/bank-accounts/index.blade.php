@extends('layouts.dashboard')

@section('page-title', 'Bank Verifications')

@section('content')
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h2 style="font-size:1.25rem;font-weight:700;color:#1b1b18;margin:0;">Bank Verifications</h2>
        <p style="font-size:0.8125rem;color:#888;margin:0.25rem 0 0;">Manage and verify payment & payout bank account document submissions</p>
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
    <a href="{{ route('admin.bank-accounts.index', ['tab' => 'payment', 'status' => $status]) }}" 
       style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; border-bottom: 3px solid {{ $tab === 'payment' ? 'var(--primary)' : 'transparent' }}; color: {{ $tab === 'payment' ? 'var(--primary)' : '#555' }}; transition: all 0.2s;">
        <i class="fa-solid fa-credit-card"></i> Buyer Payment Accounts
    </a>
    <a href="{{ route('admin.bank-accounts.index', ['tab' => 'payout', 'status' => $status]) }}" 
       style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 600; text-decoration: none; border-bottom: 3px solid {{ $tab === 'payout' ? 'var(--primary)' : 'transparent' }}; color: {{ $tab === 'payout' ? 'var(--primary)' : '#555' }}; transition: all 0.2s;">
        <i class="fa-solid fa-money-bill-transfer"></i> Seller Payout Accounts
    </a>
</div>

{{-- Filters --}}
<div class="card" style="margin-bottom:1.25rem;">
    <form method="GET" action="{{ route('admin.bank-accounts.index') }}" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="tab" value="{{ $tab }}">
        
        <div class="form-group" style="margin:0;flex:1;min-width:180px;">
            <label style="font-size:0.75rem;font-weight:600;color:#555;display:block;margin-bottom:0.3rem;">Search</label>
            <input type="text" name="search" value="{{ $search }}" placeholder="Bank, holder name, number…" style="padding:0.45rem 0.75rem;border:1px solid #ddd;border-radius:0.5rem;font-size:0.875rem;width:100%;box-sizing:border-box;outline:none;">
        </div>
        
        <div class="form-group" style="margin:0;min-width:150px;">
            <label style="font-size:0.75rem;font-weight:600;color:#555;display:block;margin-bottom:0.3rem;">Status</label>
            <select name="status" style="padding:0.45rem 0.75rem;border:1px solid #ddd;border-radius:0.5rem;font-size:0.875rem;outline:none;width:100%;height:38px;">
                <option value="">All Statuses</option>
                <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending Review</option>
                <option value="approved" {{ $status === 'approved' ? 'selected' : '' }}>Approved</option>
                <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Rejected</option>
            </select>
        </div>
        
        <div style="display:flex;gap:0.5rem;">
            <button type="submit" class="btn-primary" style="padding:0.45rem 1rem;font-size:0.8125rem;height:38px;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <a href="{{ route('admin.bank-accounts.index', ['tab' => $tab]) }}" class="btn-cancel" style="text-decoration:none;padding:0.45rem 0.75rem;font-size:0.8125rem;display:inline-flex;align-items:center;justify-content:center;height:38px;border:1px solid #ccc;border-radius:0.5rem;color:#333;">
                <i class="fa-solid fa-rotate-left"></i> Reset
            </a>
        </div>
    </form>
</div>

{{-- Table --}}
<div class="card">
    @if($accounts->isEmpty())
        <div style="text-align: center; padding: 2rem; color: #888;">
            <i class="fa-solid fa-inbox" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
            No bank accounts found matching filters.
        </div>
    @else
        <table id="dataTable" data-hide-filter="1" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid #eee;">
                    <th style="padding: 0.75rem 0.5rem;">#</th>
                    <th style="padding: 0.75rem 0.5rem;">User details</th>
                    <th style="padding: 0.75rem 0.5rem;">Bank details</th>
                    <th style="padding: 0.75rem 0.5rem;">Additional details</th>
                    <th style="padding: 0.75rem 0.5rem;">Verification docs</th>
                    <th style="padding: 0.75rem 0.5rem;">Status</th>
                    <th style="padding: 0.75rem 0.5rem; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($accounts as $acc)
                @php
                    $isPayout = ($tab === 'payout');
                    $userProfile = $isPayout ? $acc->seller : $acc->buyer;
                    
                    $statusBg = '#eff6ff';
                    $statusColor = '#1d4ed8';
                    $statusLabel = 'Pending Review';
                    
                    if ($acc->status === 'approved') {
                        $statusBg = '#f0fdf4';
                        $statusColor = '#15803d';
                        $statusLabel = 'Approved';
                    } elseif ($acc->status === 'rejected') {
                        $statusBg = '#fef2f2';
                        $statusColor = '#b91c1c';
                        $statusLabel = 'Rejected';
                    }
                @endphp
                <tr style="border-bottom: 1px solid #f8fafc; font-size: 0.8125rem;">
                    <td style="padding: 0.75rem 0.5rem; color:#aaa; width: 40px;">{{ $acc->id }}</td>
                    <td style="padding: 0.75rem 0.5rem; min-width: 180px;">
                        <div style="font-weight:600;font-size:0.875rem;">{{ $userProfile?->user?->name ?? '—' }}</div>
                        <div style="color:#888;font-size:0.75rem;">{{ $userProfile?->user?->email ?? '' }}</div>
                    </td>
                    <td style="padding: 0.75rem 0.5rem; min-width: 180px;">
                        <div style="font-weight: 600; color: #333;">{{ $acc->bank_name }}</div>
                        <div style="font-size: 0.75rem; color: #555;">Name: {{ $acc->account_name }}</div>
                        <div style="font-size: 0.75rem; font-family: monospace; color: #222;">
                            No: {{ $isPayout ? $acc->account_number : ('****' . substr($acc->account_number_last4, -4)) }}
                        </div>
                    </td>
                    <td style="padding: 0.75rem 0.5rem;">
                        @if($acc->sort_code)
                            <div><span style="color:#666; font-size: 0.75rem;">Sort Code:</span> <span style="font-family: monospace;">{{ $acc->sort_code }}</span></div>
                        @endif
                        @if($acc->iban)
                            <div><span style="color:#666; font-size: 0.75rem;">IBAN:</span> <span style="font-family: monospace; font-size: 0.75rem;">{{ $acc->iban }}</span></div>
                        @endif
                        @if($acc->swift_code)
                            <div><span style="color:#666; font-size: 0.75rem;">SWIFT:</span> <span style="font-family: monospace;">{{ $acc->swift_code }}</span></div>
                        @endif
                    </td>
                    <td style="padding: 0.75rem 0.5rem;">
                        <div style="margin-bottom: 0.35rem;">
                            @if($acc->statement_path)
                                <a href="{{ asset('storage/' . $acc->statement_path) }}" target="_blank" class="btn-view" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; border-radius: 0.25rem; display: inline-flex; align-items: center; gap: 0.25rem; background: var(--primary); text-decoration: none; color: #fff;">
                                    <i class="fa-solid fa-file-pdf"></i> View Statement
                                </a>
                            @else
                                <span style="color:#aaa; font-style: italic;">No statement uploaded</span>
                            @endif
                        </div>
                        @if(!$isPayout)
                            <div>
                                @if($acc->is_own_account)
                                    <span style="color: #16a34a; font-weight: 500; font-size: 0.75rem;"><i class="fa-solid fa-shield-halved"></i> Own account declared</span>
                                @else
                                    <span style="color: #dc2626; font-weight: 500; font-size: 0.75rem;"><i class="fa-solid fa-circle-exclamation"></i> Third party account</span>
                                @endif
                            </div>
                        @endif
                    </td>
                    <td style="padding: 0.75rem 0.5rem;">
                        <span class="badge" style="background:{{ $statusBg }}; color:{{ $statusColor }}; padding:0.25rem 0.5rem; border-radius:0.25rem; font-weight:600; font-size:0.75rem; display:inline-block;">
                            {{ $statusLabel }}
                        </span>
                    </td>
                    <td style="padding: 0.75rem 0.5rem; text-align: right;">
                        <div style="display:inline-flex; gap: 0.35rem;">
                            @if($acc->status === 'pending')
                                <form method="POST" action="{{ route('admin.bank-accounts.approve', ['type' => $tab, 'id' => $acc->id]) }}" style="margin: 0;">
                                    @csrf
                                    <button type="submit" class="btn-approve" style="padding: 0.35rem 0.75rem; background: #16a34a; color: #fff; border: none; border-radius: 0.25rem; cursor: pointer; font-size: 0.75rem; font-weight: 600;">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.bank-accounts.reject', ['type' => $tab, 'id' => $acc->id]) }}" style="margin: 0;">
                                    @csrf
                                    <button type="submit" class="btn-danger" style="padding: 0.35rem 0.75rem; background: #dc2626; color: #fff; border: none; border-radius: 0.25rem; cursor: pointer; font-size: 0.75rem; font-weight: 600;">
                                        <i class="fa-solid fa-xmark"></i> Reject
                                    </button>
                                </form>
                            @else
                                <span style="color:#aaa; font-size:0.75rem;">No action needed</span>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <div style="margin-top: 1.25rem;">
            {{ $accounts->links() }}
        </div>
    @endif
</div>
@endsection
