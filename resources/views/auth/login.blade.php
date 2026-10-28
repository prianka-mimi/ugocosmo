@component('auth.layout', ['title' => 'Login'])
    <div class="auth-logo">
        <a href="{{ url('/') }}">
            <img src="{{ asset('img/logo.png') }}" alt="Logo"/>
        </a>
    </div>
    <div class="auth-card">
        <div class="auth-header">
            <h2>Login</h2>
            <a href="{{ url('/') }}" class="back-btn">
                <i class="fa-solid fa-arrow-left" style="margin-right: 0.3rem;"></i> Back
            </a>
        </div>
        <p class="auth-subtitle">Welcome! Please login to your account.</p>
        @if ($errors->any())
            <div class="error-box">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" placeholder="Enter your email" value="{{ old('email') }}" required />
            </div>
            <div class="pwd-group">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="Enter your password" required />
                <button type="button" class="eye-btn" onclick="togglePasswordVisibility(this)">
                    <i class="fa-solid fa-eye eye-open"></i>
                    <span class="eye-closed" style="display:none;"><i class="fa-solid fa-eye-slash"></i></span>
                </button>
            </div>
            <div class="checkbox-row">
                <input id="remember" name="remember" type="checkbox" />
                <label for="remember">Remember me</label>
                <a href="{{ route('password.request') }}" class="forgot-link">Forgot password?</a>
            </div>
            <button type="submit" class="btn">Login</button>
        </form>
        <p class="auth-footer">
            Don't have an account? <a href="{{ route('register') }}" class="link">Register</a>
        </p>
    </div>
@endcomponent
