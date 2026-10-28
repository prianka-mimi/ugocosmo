@component('auth.layout', ['title' => 'Reset Password'])
    <div class="auth-card">
        <div class="auth-header">
            <h2>Reset Password</h2>
            <a href="{{ route('login') }}" class="back-btn">
                <i class="fa-solid fa-arrow-left" style="margin-right: 0.3rem;"></i> Back
            </a>
        </div>
        @if ($errors->any())
            <div class="error-box">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('password.store') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" placeholder="you@example.com" value="{{ old('email', $request->email) }}" required autofocus />
            </div>
            <div class="pwd-group">
                <label for="password">New Password</label>
                <input id="password" name="password" type="password" placeholder="Create new password" required />
            </div>
            <div class="pwd-group">
                <label for="password_confirmation">Confirm New Password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" placeholder="Confirm new password" required />
            </div>
            <button type="submit" class="btn">Reset Password</button>
        </form>
    </div>
@endcomponent
