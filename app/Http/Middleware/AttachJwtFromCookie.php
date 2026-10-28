<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AttachJwtFromCookie
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. If a valid Authorization header is already present, proceed immediately
        $authHeader = $request->header('Authorization');
        if (! empty($authHeader) && trim($authHeader) !== 'Bearer' && trim($authHeader) !== 'Bearer null') {
            return $next($request);
        }

        // 2. Extract token — parse raw Cookie header first (works for unencrypted/external cookies),
        //    then fall back to Laravel's cookie helpers and query string.
        $token = null;

        if ($request->headers->has('cookie')) {
            $rawCookie = (string) $request->header('cookie');
            if (preg_match('/(?:^|;\s*)token=([^;]+)/', $rawCookie, $matches)) {
                $token = urldecode(trim($matches[1], "\"'\t\n\r\0\x0B "));
            }
        }

        if (empty($token)) {
            $token = $_COOKIE['token'] ?? null;
        }

        if (empty($token)) {
            try {
                $token = $request->cookie('token') ?? $request->cookies->get('token');
            } catch (\Throwable) {
                $token = null;
            }
        }

        if (empty($token)) {
            $token = $request->query('token');
        }

        if (! empty($token)) {
            $tokenStr = trim((string) $token, "\"'\t\n\r\0\x0B ");
            $cleanToken = str_starts_with($tokenStr, 'Bearer ') ? substr($tokenStr, 7) : $tokenStr;
            $headerVal = 'Bearer '.$cleanToken;

            $request->headers->set('Authorization', $headerVal);
            $request->server->set('HTTP_AUTHORIZATION', $headerVal);

            try {
                $guard = Auth::guard('jwt');
                $guard->setRequest($request);
                $user = $guard->user();

                if (! $user) {
                    $jwt = app('tymon.jwt');
                    $jwt->setToken($cleanToken);
                    if ($payload = $jwt->check(true)) {
                        $sub = $payload['sub'] ?? ($payload->get('sub') ?? null);
                        if ($sub) {
                            $user = User::find($sub);
                        }
                    }
                }

                if ($user) {
                    Auth::setUser($user);
                    $guard->setUser($user);
                    $request->setUserResolver(fn () => $user);
                }
            } catch (\Throwable) {
            }

            return $next($request);
        }

        // 3. Fallback: If user is authenticated via web session cookie (e.g. from admin panel)
        $sessionCookieName = config('session.cookie', 'castilo-session');
        $rawSessionCookie = $request->cookie($sessionCookieName) ?? $request->cookies->get($sessionCookieName);

        if ($rawSessionCookie) {
            try {
                $decrypted = app('encrypter')->decrypt($rawSessionCookie, false);
                if (is_string($decrypted)) {
                    if (str_contains($decrypted, '|')) {
                        $decrypted = explode('|', $decrypted, 2)[1];
                    }
                    $session = DB::table('sessions')->where('id', $decrypted)->first();
                    if ($session && ! empty($session->user_id)) {
                        $user = User::find($session->user_id);
                        if ($user) {
                            $token = Auth::guard('jwt')->fromUser($user);
                            if ($token) {
                                $request->headers->set('Authorization', 'Bearer '.$token);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Silently ignore decryption / lookup errors and let standard auth handle it
            }
        }

        return $next($request);
    }
}
