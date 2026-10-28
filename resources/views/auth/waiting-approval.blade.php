@component('auth.layout', ['title' => 'Waiting for Approval'])
    <div class="auth-card" style="text-align: center;">
        <div style="width: 64px; height: 64px; border-radius: 50%; background: #fef9c3; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
            <i class="fa-solid fa-clock" style="font-size: 1.75rem; color: #eab308;"></i>
        </div>
        <h2 style="font-size: 1.25rem; font-weight: 600; color: #1b1b18; margin-bottom: 0.75rem;">Waiting for Approval</h2>
        <p style="color: #666; font-size: 0.875rem; line-height: 1.6; margin-bottom: 0.5rem;">Your account has been created successfully. An administrator will review your registration and activate your account soon.</p>
        <p style="color: #888; font-size: 0.8rem;">You will be able to log in once your account is approved.</p>
        <a href="{{ route('login') }}" class="btn" style="display: inline-flex; align-items: center; gap: 0.375rem; margin-top: 1rem; width: auto; padding: 0.6rem 1.5rem; text-decoration: none;">
            <i class="fa-solid fa-arrow-left"></i> Back to Login
        </a>
    </div>
@endcomponent
