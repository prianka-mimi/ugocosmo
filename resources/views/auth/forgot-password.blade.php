@component('auth.layout', ['title' => 'Forgot Password'])
    <div class="auth-logo">
        <a href="{{ url('/') }}">
            <img src="{{ asset('img/logo.png') }}" alt="Logo"/>
        </a>
    </div>
    <div class="auth-card">
        <div class="auth-header">
            <h2>Forgot Password</h2>
            <a href="{{ route('login') }}" class="back-btn">
                <i class="fa-solid fa-arrow-left" style="margin-right: 0.3rem;"></i> Back
            </a>
        </div>
        <p class="auth-subtitle">No problem. Enter your email and we'll send a reset link.</p>
        @if (session('status'))
            <div class="error-box success">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="error-box">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" placeholder="you@example.com" value="{{ old('email') }}" required autofocus />
            </div>
            <button type="submit" class="btn">Send Reset Link</button>
        </form>
    </div>
@endcomponent
