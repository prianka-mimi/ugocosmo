@extends('layouts.dashboard')

@section('page-title', 'Bank Settings')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Admin Bank Details (For Escrow Payments)</h3>
    </div>

    <form method="POST" action="{{ route('settings.bank.update') }}">
        @csrf
        
        <div class="form-group">
            <label for="bank_account_name">Account Name</label>
            <input type="text" id="bank_account_name" name="bank_account_name" value="{{ old('bank_account_name', $settings['bank_account_name']) }}" placeholder="Castilo Limited" />
        </div>

        <div class="form-group">
            <label for="bank_account_number">Account Number</label>
            <input type="text" id="bank_account_number" name="bank_account_number" value="{{ old('bank_account_number', $settings['bank_account_number']) }}" placeholder="12345678" />
        </div>

        <div class="form-group">
            <label for="bank_sort_code">Sort Code</label>
            <input type="text" id="bank_sort_code" name="bank_sort_code" value="{{ old('bank_sort_code', $settings['bank_sort_code']) }}" placeholder="00-00-00" />
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
