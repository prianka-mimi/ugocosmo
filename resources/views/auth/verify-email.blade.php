@component('auth.layout', ['title' => 'Verify Email'])
    <div class="auth-logo">
        <a href="{{ url('/') }}">
            <img src="{{ asset('img/logo.png') }}" alt="Logo"/>
        </a>
    </div>
    <div class="auth-card">
        <div class="auth-header">
            <h2>Verify Email</h2>
            <a href="{{ url('/') }}" class="back-btn">
                <i class="fa-solid fa-arrow-left" style="margin-right: 0.3rem;"></i> Back
            </a>
        </div>
        <p class="auth-subtitle">Thanks for signing up! Please verify your email by clicking the link we sent.</p>
        @if (session('status') == 'verification-link-sent')
            <div class="error-box success">
                A new verification link has been sent to your email.
            </div>
        @endif
        <div style="margin-bottom: 1rem;">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="btn">Resend Email</button>
            </form>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-outline">Log Out</button>
        </form>
    </div>
@endcomponent
