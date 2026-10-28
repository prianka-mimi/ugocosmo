<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Spatie\Permission\PermissionRegistrar;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = Auth::user();
        if ($user) {
            try {
                $jwtToken = Auth::guard('jwt')->fromUser($user);
                cookie()->queue(cookie(
                    'token',
                    $jwtToken,
                    config('jwt.ttl', 10080),
                    '/',
                    config('session.domain'),
                    config('session.secure', false),
                    true,
                    false,
                    config('session.same_site', 'lax')
                ));
            } catch (\Throwable $e) {
                // Silently ignore if JWT generation fails
            }
        }

        $route = in_array($user->role, ['admin', 'supervisor']) ? 'admin.dashboard' : 'dashboard';

        return redirect()->intended(route($route, absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        cookie()->queue(cookie()->forget('token'));

        return redirect('/');
    }
}
