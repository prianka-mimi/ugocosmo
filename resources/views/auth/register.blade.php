@component('auth.layout', ['title' => 'Register'])
    <div class="auth-logo">
        <a href="{{ url('/') }}">
            <img src="{{ asset('img/logo.png') }}" alt="Logo"/>
        </a>
    </div>
    <div class="auth-card">
        <div class="auth-header">
            <h2>Register</h2>
            <a href="{{ url('/') }}" class="back-btn">
                <i class="fa-solid fa-arrow-left" style="margin-right: 0.3rem;"></i> Back
            </a>
        </div>
        <p class="auth-subtitle">Join us! Create your account to get started.</p>
        @if ($errors->any())
            <div class="error-box">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('register') }}">
            @csrf
            <div class="form-group">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" placeholder="Full Name" value="{{ old('name') }}" required autofocus />
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" placeholder="you@example.com" value="{{ old('email') }}" required />
            </div>
            <div class="pwd-group">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="Create password" required />
                <button type="button" class="eye-btn" onclick="togglePasswordVisibility(this)">
                    <i class="fa-solid fa-eye eye-open"></i>
                    <span class="eye-closed" style="display:none;"><i class="fa-solid fa-eye-slash"></i></span>
                </button>
            </div>
            <div class="pwd-group">
                <label for="password_confirmation">Confirm Password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" placeholder="Confirm password" required />
                <button type="button" class="eye-btn" onclick="togglePasswordVisibility(this)">
                    <i class="fa-solid fa-eye eye-open"></i>
                    <span class="eye-closed" style="display:none;"><i class="fa-solid fa-eye-slash"></i></span>
                </button>
            </div>
            <button type="submit" class="btn">Register</button>
        </form>
        <p class="auth-footer">
            Already have an account? <a href="{{ route('login') }}" class="link">Login</a>
        </p>
    </div>
@endcomponent
