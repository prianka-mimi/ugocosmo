@extends('layouts.dashboard')

@section('page-title', 'Verification Detail')

@section('content')

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
    <a href="{{ route('admin.verifications.index') }}" class="btn-cancel" style="text-decoration:none;">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>
    <div>
        <h2 style="font-size:1.25rem;font-weight:700;color:#1b1b18;margin:0;">Verification #{{ $submission->id }}</h2>
        <p style="font-size:0.8125rem;color:#888;margin:0.2rem 0 0;">Submitted {{ $submission->created_at?->format('d M Y, H:i') }}</p>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('checkout_url'))
    <div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;margin-bottom:1rem;">
        <strong style="display:block;margin-bottom:0.35rem;">Payment Link</strong>
        <a href="{{ session('checkout_url') }}" target="_blank" style="color:#1d4ed8;word-break:break-all;">{{ session('checkout_url') }}</a>
    </div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;">{{ session('error') }}</div>
@endif

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

    {{-- ── LEFT COLUMN ─────────────────────────────────────── --}}
    <div style="display:flex;flex-direction:column;gap:1.25rem;">

        {{-- Status Card --}}
        <div class="card">
            <h3 style="font-size:0.9375rem;font-weight:700;margin:0 0 1rem;color:#1b1b18;">Submission Status</h3>
            <div style="display:flex;flex-direction:column;gap:0.6rem;">
                @php
                    $statusMap = [
                        'payment_pending' => ['bg'=>'#fff7ed','color'=>'#c2410c','label'=>'Payment Pending'],
                        'pending'         => ['bg'=>'#eff6ff','color'=>'#1d4ed8','label'=>'Pending Review'],
                        'approved'        => ['bg'=>'#f0fdf4','color'=>'#166534','label'=>'Approved'],
                        'rejected'        => ['bg'=>'#fef2f2','color'=>'#b91c1c','label'=>'Rejected'],
                    ];
                    $s = $statusMap[$submission->status] ?? ['bg'=>'#f5f5f5','color'=>'#555','label'=>ucfirst($submission->status)];
                @endphp
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Verification Status</span>
                    <span class="badge" style="background:{{ $s['bg'] }};color:{{ $s['color'] }};font-size:0.8125rem;">{{ $s['label'] }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Payment Status</span>
                    @if($submission->payment_status === 'completed')
                        <span class="badge" style="background:#f0fdf4;color:#166534;">Paid</span>
                    @else
                        <span class="badge" style="background:#fff7ed;color:#c2410c;">Unpaid</span>
                    @endif
                </div>
                @if($submission->stripe_payment_intent_id)
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                    <span style="font-size:0.8125rem;color:#555;white-space:nowrap;">Stripe Reference</span>
                    <code style="font-size:0.75rem;color:#555;word-break:break-all;">{{ $submission->stripe_payment_intent_id }}</code>
                </div>
                @endif
                @if($submission->reviewed_at)
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Reviewed At</span>
                    <span style="font-size:0.8125rem;">{{ $submission->reviewed_at?->format('d M Y, H:i') }}</span>
                </div>
                @endif
                @if($submission->reviewer)
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Reviewed By</span>
                    <span style="font-size:0.8125rem;">{{ $submission->reviewer?->user?->name ?? '—' }}</span>
                </div>
                @endif
            </div>
        </div>

        {{-- Payment Invoice Card --}}
        @if((float)($submission->subscriptionPlan?->price) > 0)
        <div class="card">
            <h3 style="font-size:0.9375rem;font-weight:700;margin:0 0 1rem;color:#1b1b18;">Payment Invoice</h3>
            <div style="display:flex;flex-direction:column;gap:0.6rem;">
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Amount</span>
                    <span style="font-size:0.8125rem;font-weight:600;">
                        @if($paymentDetails && $paymentDetails['amount'])
                            {{ $paymentDetails['currency'] === 'USD' ? '$' : '£' }}{{ number_format($paymentDetails['amount'], 2) }}
                        @else
                            £{{ number_format($submission->subscriptionPlan->price, 2) }}
                        @endif
                    </span>
                </div>
                @if($paymentDetails)
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Stripe Status</span>
                    <span style="font-size:0.8125rem;text-transform:capitalize;">{{ str_replace('_', ' ', $paymentDetails['payment_status']) }}</span>
                </div>
                @if($paymentDetails['paid_at'])
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Paid At</span>
                    <span style="font-size:0.8125rem;">{{ \Carbon\Carbon::parse($paymentDetails['paid_at'])->format('d M Y, H:i') }}</span>
                </div>
                @endif
                @if($paymentDetails['receipt_url'])
                <div style="margin-top:0.25rem;">
                    <a href="{{ $paymentDetails['receipt_url'] }}" target="_blank" class="btn-view-icon" style="font-size:0.75rem;padding:0.3rem 0.6rem;text-decoration:none;">
                        <i class="fa-solid fa-receipt"></i> View Stripe Receipt
                    </a>
                </div>
                @endif
                @if($paymentDetails['checkout_url'] && $submission->payment_status !== 'completed')
                <div style="margin-top:0.25rem;">
                    <a href="{{ $paymentDetails['checkout_url'] }}" target="_blank" class="btn-view-icon" style="font-size:0.75rem;padding:0.3rem 0.6rem;text-decoration:none;">
                        <i class="fa-solid fa-link"></i> Open Checkout Link
                    </a>
                </div>
                @endif
                @else
                <p style="font-size:0.8125rem;color:#888;margin:0;">No Stripe payment record yet.</p>
                @endif

                @can('verifications.manage')
                @if($submission->payment_status !== 'completed')
                {{-- <form method="POST" action="{{ route('admin.verifications.generate-payment', $submission) }}" style="margin-top:0.75rem;"
                      class="swal-confirm"
                      data-swal-title="Generate Payment Link"
                      data-swal-text="Generate a new Stripe payment link?"
                      data-swal-confirm-text="Generate Link">
                    @csrf
                    <button type="submit" class="btn-approve" style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-link"></i> Generate Payment Link
                    </button>
                </form> --}}
                @endif
                @endcan
            </div>
        </div>
        @endif

        {{-- User Info Card --}}
        <div class="card">
            <h3 style="font-size:0.9375rem;font-weight:700;margin:0 0 1rem;color:#1b1b18;">User Information</h3>
            <div style="display:flex;flex-direction:column;gap:0.5rem;">
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Name</span>
                    <span style="font-size:0.8125rem;">{{ $submission->user?->user?->name ?? '—' }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Email</span>
                    <span style="font-size:0.8125rem;">{{ $submission->user?->user?->email ?? '—' }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Username</span>
                    <span style="font-size:0.8125rem;">{{ $submission->user?->username ?? '—' }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Verified?</span>
                    @if($submission->user?->user?->is_verified)
                        <span class="badge" style="background:#f0fdf4;color:#166534;"><i class="fa-solid fa-check"></i> Yes</span>
                    @else
                        <span class="badge" style="background:#fef2f2;color:#b91c1c;">No</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Plan Card --}}
        <div class="card">
            <h3 style="font-size:0.9375rem;font-weight:700;margin:0 0 1rem;color:#1b1b18;">Subscription Plan</h3>
            <div style="display:flex;flex-direction:column;gap:0.5rem;">
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Plan</span>
                    <span style="font-size:0.8125rem;font-weight:600;">{{ $submission->subscriptionPlan?->name ?? '—' }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-size:0.8125rem;color:#555;">Price</span>
                    <span style="font-size:0.8125rem;">
                        @if((float)($submission->subscriptionPlan?->price) === 0.0)
                            <span style="color:#166534;font-weight:600;">Free</span>
                        @else
                            £{{ number_format($submission->subscriptionPlan->price, 2) }}/mo
                        @endif
                    </span>
                </div>
            </div>
        </div>

    </div>{{-- end left --}}

    {{-- ── RIGHT COLUMN ────────────────────────────────────── --}}
    <div style="display:flex;flex-direction:column;gap:1.25rem;">

        {{-- Personal Details Card --}}
        <div class="card">
            <h3 style="font-size:0.9375rem;font-weight:700;margin:0 0 1rem;color:#1b1b18;">Personal Details</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                @foreach([
                    'First Name'           => $submission->first_name,
                    'Last Name'            => $submission->last_name,
                    'Business Name'        => $submission->business_name,
                    'Country'              => $submission->country,
                    'City'                 => $submission->city,
                    'Town'                 => $submission->town,
                    'Address Line 1'       => $submission->address_line_1,
                    'Address Line 2'       => $submission->address_line_2,
                    'Postal Code'          => $submission->postal_code,
                ] as $label => $value)
                @if($value)
                <div style="font-size:0.75rem;color:#888;">{{ $label }}</div>
                <div style="font-size:0.8125rem;color:#333;word-break:break-word;">{{ $value }}</div>
                @endif
                @endforeach
            </div>
            @if($submission->business_description)
            <div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #f0f0f0;">
                <div style="font-size:0.75rem;color:#888;margin-bottom:0.25rem;">Business Description</div>
                <p style="font-size:0.8125rem;color:#333;line-height:1.6;margin:0;">{{ $submission->business_description }}</p>
            </div>
            @endif
        </div>

        {{-- Documents Card --}}
        <div class="card">
            <h3 style="font-size:0.9375rem;font-weight:700;margin:0 0 1rem;color:#1b1b18;">Submitted Documents</h3>
            @forelse($documents as $doc)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.6rem 0;border-bottom:1px solid #f5f5f5;">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <i class="fa-solid fa-file-lines" style="color:var(--primary);width:1rem;"></i>
                    <span style="font-size:0.8125rem;color:#333;">{{ $doc['label'] }}</span>
                </div>
                <a href="{{ $doc['url'] }}" target="_blank" class="btn-view-icon" title="View Document" style="font-size:0.75rem;padding:0.3rem 0.6rem;">
                    <i class="fa-solid fa-external-link"></i> View
                </a>
            </div>
            @empty
            <p style="font-size:0.8125rem;color:#aaa;text-align:center;margin:1rem 0;">No documents uploaded.</p>
            @endforelse

            @php
                $imageDocs = array_filter($documents, function($doc) {
                    $ext = strtolower(pathinfo(parse_url($doc['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
                    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp']);
                });
            @endphp

            @if(count($imageDocs) > 0)
            <div style="margin-top:1.5rem;border-top:1px solid #eee;padding-top:1rem;">
                <h4 style="font-size:0.8125rem;font-weight:700;margin:0 0 0.75rem;color:#555;">Image Previews</h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(130px, 1fr));gap:0.75rem;">
                    @foreach($imageDocs as $doc)
                    <div style="border:1px solid #e5e7eb;border-radius:0.375rem;overflow:hidden;background:#f9fafb;display:flex;flex-direction:column;">
                        <a href="{{ $doc['url'] }}" target="_blank" style="display:block;line-height:0;">
                            <img src="{{ $doc['url'] }}" style="width:100%;height:110px;object-fit:cover;" alt="{{ $doc['label'] }}">
                        </a>
                        <div style="padding:0.4rem;font-size:0.7rem;font-weight:600;color:#374151;text-align:center;border-top:1px solid #e5e7eb;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $doc['label'] }}">
                            {{ $doc['label'] }}
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        {{-- Admin Notes (read-only) --}}
        @if($submission->admin_notes)
        <div class="card">
            <h3 style="font-size:0.9375rem;font-weight:700;margin:0 0 0.75rem;color:#1b1b18;">Admin Notes</h3>
            <p style="font-size:0.8125rem;color:#555;line-height:1.6;margin:0;">{{ $submission->admin_notes }}</p>
        </div>
        @endif

        {{-- Rejection Reason (if rejected) --}}
        @if($submission->status === 'rejected' && $submission->rejection_reason)
        <div class="card" style="border-left:4px solid #ef4444;">
            <h3 style="font-size:0.9375rem;font-weight:700;margin:0 0 0.75rem;color:#ef4444;">Rejection Reason</h3>
            <p style="font-size:0.8125rem;color:#555;line-height:1.6;margin:0;">{{ $submission->rejection_reason }}</p>
        </div>
        @endif

        {{-- Action Buttons --}}
        @if(!in_array($submission->status, ['approved', 'rejected']))
        <div class="card">
            <h3 style="font-size:0.9375rem;font-weight:700;margin:0 0 1rem;color:#1b1b18;">Review Actions</h3>

            @if($submission->payment_status !== 'completed' && (float)($submission->subscriptionPlan?->price) > 0)
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.8125rem;color:#c2410c;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Payment has not been confirmed for this submission. You can still approve it.
            </div>
            @endif

            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                {{-- Approve --}}
                @can('verifications.manage')
                <form method="POST" action="{{ route('admin.verifications.approve', $submission) }}" style="display:inline;"
                      class="swal-confirm"
                      data-swal-title="Approve Verification"
                      data-swal-text="Approve this verification and activate the user's subscription?"
                      data-swal-confirm-text="Approve">
                    @csrf
                    <button type="submit" class="btn-approve">
                        <i class="fa-solid fa-check"></i> Approve Verification
                    </button>
                </form>
                @endcan

                {{-- Reject --}}
                @can('verifications.manage')
                <button type="button" class="btn-reject" onclick="confirmRejectVerification()">
                    <i class="fa-solid fa-xmark"></i> Reject
                </button>
                <form method="POST" action="{{ route('admin.verifications.reject', $submission) }}" id="rejectVerificationForm" style="display:none;">
                    @csrf
                    <input type="hidden" name="rejection_reason" id="rejectReasonInput" value="">
                </form>
                @endcan
            </div>
        </div>
        @endif

    </div>{{-- end right --}}

</div>{{-- end grid --}}

@endsection
