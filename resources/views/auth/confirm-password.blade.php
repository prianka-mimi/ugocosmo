@component('auth.layout', ['title' => 'Confirm Password'])
    <div class="auth-card">
        <div class="auth-header">
            <h2>Confirm Password</h2>
            <a href="{{ route('login') }}" class="back-btn">
                <i class="fa-solid fa-arrow-left" style="margin-right: 0.3rem;"></i> Back
            </a>
        </div>
        <p class="auth-subtitle">This is a secure area. Please confirm your password.</p>
        @if ($errors->any())
            <div class="error-box">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('password.confirm') }}">
            @csrf
            <div class="pwd-group">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="Enter your password" required />
                <button type="button" class="eye-btn" onclick="togglePasswordVisibility(this)">
                    <i class="fa-solid fa-eye eye-open"></i>
                    <span class="eye-closed" style="display:none;"><i class="fa-solid fa-eye-slash"></i></span>
                </button>
            </div>
            <button type="submit" class="btn">Confirm</button>
        </form>
    </div>
@endcomponent
