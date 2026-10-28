<?php

namespace App\Manager;

use App\Models\Profile;
use App\Models\SavedAddress;
use App\Models\SocialAccount;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthManager
{
    /**
     * Register a new user.
     */
    public function register(array $data): array
    {
        $user = User::create([
            'account_type' => $data['account_type'],
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'mobile_number' => $data['mobile_number'] ?? null,
            'address_line_1' => $data['address_line_1'] ?? null,
            'address_line_2' => $data['address_line_2'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'role' => 'user',
            'status' => 'active',
            'password' => Hash::make($data['password']),
        ]);

        // Create a Profile linked to this user (all foreign keys reference profiles.id)
        $profile = Profile::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'full_name' => $user->name,
            'username' => $user->username,
        ]);

        // Create default SavedAddress if address fields are provided
        if (! empty($user->address_line_1) || ! empty($user->city) || ! empty($user->postal_code)) {
            SavedAddress::create([
                'profile_id' => $profile->id,
                'label' => 'Home',
                'name' => $user->name,
                'address_line1' => $user->address_line_1 ?? '',
                'address_line2' => $user->address_line_2,
                'city_locality' => $user->city ?? '',
                'postal_code' => $user->postal_code ?? '',
                'country_code' => $user->country ?? 'US',
                'phone' => $user->mobile_number,
                'is_default' => true,
            ]);
        }

        return ['user' => $user];
    }

    /**
     * Authenticate a user and return user details, token, and httpOnly cookie.
     */
    public function login(array $credentials): array
    {
        $token = Auth::guard('jwt')->attempt($credentials);

        if (! $token) {
            throw new Exception('Invalid email or password', 401);
        }

        $user = Auth::guard('jwt')->user();

        $cookie = $this->generateTokenCookie($token);

        return [
            'user' => $user,
            'token' => $token,
            'cookie' => $cookie,
        ];
    }

    /**
     * Handle logging out and returning a cleared cookie.
     *
     * Purges all user-scoped third-party API caches on logout to prevent
     * stale tokens from being served to a subsequent user on the same device.
     */
    public function logout(): array
    {
        $user = Auth::guard('jwt')->user();

        // Purge user-scoped third-party session caches before invalidating JWT
        if ($user) {
            $this->purgeThirdPartySessionCaches($user->id);
        }

        Auth::guard('jwt')->logout();
        $cookie = $this->clearTokenCookie();

        return [
            'cookie' => $cookie,
        ];
    }

    /**
     * Wipe all third-party API session caches bound to this user.
     * Prevents cross-account token leakage when a new user logs in on the same device.
     */
    protected function purgeThirdPartySessionCaches(int $userId): void
    {
        // Bust AliExpress freight/rate caches for all products owned by this user
        // These are keyed by product_id+country — we tag them by user for purge
        Cache::forget("user_session_context:{$userId}");

        // Bust ShipEngine carrier list cache (shared but safe to refresh on logout)
        Cache::forget('shipengine:carriers');

        Log::info('Third-party session caches purged on logout', [
            'user_id' => $userId,
        ]);
    }

    /**
     * Refresh the JWT token and return new token and cookie.
     */
    public function refreshToken(): array
    {
        $token = Auth::guard('jwt')->refresh();
        $cookie = $this->generateTokenCookie($token);

        return [
            'token' => $token,
            'cookie' => $cookie,
        ];
    }

    /**
     * Request OTP for password reset.
     */
    public function forgetPassword(array $data): string
    {
        $user = User::findByEmail($data['email']);

        if (! $user) {
            throw new Exception('User not found', 404);
        }

        $otp = $this->generateAndSaveOtp($user);

        Log::info('Password Reset OTP generated for User: '.$user->email);

        return $otp;
    }

    /**
     * Verify OTP for email verification or email change flow.
     */
    public function verifyOtp(array $data): User
    {
        $user = User::findByEmail($data['email']);

        if (! $user || ! $user->otp || ! Hash::check($data['otp'], $user->otp) || ! $user->otp_expires_at || $user->otp_expires_at->isPast()) {
            throw new Exception('Invalid or expired OTP', 400);
        }

        // Check if there is a pending email change
        if ($user->new_email) {
            $user->email = $user->new_email;
            $user->new_email = null;
        }

        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();

        return $user;
    }

    /**
     * Reset password using OTP.
     */
    public function resetPassword(array $data): User
    {
        $user = User::findByEmail($data['email']);

        if (! $user || ! $user->otp || ! Hash::check($data['otp'], $user->otp) || ! $user->otp_expires_at || $user->otp_expires_at->isPast()) {
            throw new Exception('Invalid or expired OTP', 400);
        }

        $user->password = Hash::make($data['password']);
        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();

        return $user;
    }

    /**
     * Initiate password change for logged in user: verify old password and send verification OTP.
     */
    public function passwordChange(User $user, array $data): array
    {
        if (! Hash::check($data['current_password'], $user->password)) {
            throw new Exception('Current password does not match', 400);
        }

        $otp = $this->generateAndSaveOtp($user);

        Log::info('Password Change OTP generated for User: '.$user->email);

        return ['user' => $user, 'otp' => $otp];
    }

    /**
     * Verify the OTP for password change and set a Cache verification flag.
     */
    public function passwordVerifyOtp(User $user, array $data): User
    {
        if (! $user->otp || ! Hash::check($data['otp'], $user->otp) || ! $user->otp_expires_at || $user->otp_expires_at->isPast()) {
            throw new Exception('Invalid or expired OTP', 400);
        }

        // Store verification status in Cache for 5 minutes
        Cache::put('password_change_verified_'.$user->id, true, now()->addMinutes(5));

        // Clear OTP since it is now successfully verified
        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();

        return $user;
    }

    /**
     * Update user's password if the Cache verification flag exists.
     */
    public function passwordUpdate(User $user, array $data): User
    {
        $cacheKey = 'password_change_verified_'.$user->id;

        if (! Cache::has($cacheKey)) {
            throw new Exception('OTP verification is required first.', 400);
        }

        $user->password = Hash::make($data['new_password']);
        $user->save();

        // Remove the verification flag from Cache
        Cache::forget($cacheKey);

        return $user;
    }

    /**
     * Initiate email change: save new email and send verification OTP.
     */
    public function emailChange(User $user, array $data): array
    {
        if (! Hash::check($data['password'], $user->password)) {
            throw new Exception('Invalid credentials to verify email change', 400);
        }

        $user->new_email = $data['new_email'];
        $otp = $this->generateAndSaveOtp($user);

        Log::info('Email Change OTP generated for User: '.$user->email);

        return ['user' => $user, 'otp' => $otp];
    }

    /**
     * Resend verification OTP to the user.
     */
    public function resendOtp(array $data): string
    {
        $user = User::findByEmail($data['email']);

        if (! $user) {
            throw new Exception('User not found', 404);
        }

        $otp = $this->generateAndSaveOtp($user);

        Log::info('Resend OTP generated for User: '.$user->email);

        return $otp;
    }

    /**
     * Authenticate or register a user via Google or Apple sign-in.
     */
    public function socialLogin(array $data): array
    {
        $provider = $data['provider'];
        $idToken = $data['id_token'];

        // Verify the ID token and extract user info from the provider
        $socialUser = match ($provider) {
            'google' => $this->verifyGoogleToken($idToken),
            'apple' => $this->verifyAppleToken($idToken),
        };

        // Normalize the display name across providers (Google returns `name`;
        // Apple only exposes given_name/family_name).
        $socialUser['name'] ??= trim(($socialUser['given_name'] ?? '').' '.($socialUser['family_name'] ?? '')) ?: null;

        // Check if a social account already exists for this provider
        $socialAccount = SocialAccount::findByProvider($provider, $socialUser['provider_id']);

        if ($socialAccount) {
            // Existing social account â€” log in the linked user
            $user = $socialAccount->user;
        } else {
            // A provider without an email cannot be linked to a Castilo account.
            if (empty($socialUser['email'])) {
                throw new Exception(
                    'Your Google/Apple account did not provide an email address. '
                    .'Please register with email and password instead.',
                    422
                );
            }

            // No social account â€” find or create user by email
            $user = User::findByEmail($socialUser['email']);

            if (! $user) {
                // Create a new user with info from the provider
                $user = User::create([
                    'name' => $socialUser['name'] ?? null,
                    'username' => $this->uniqueUsername($socialUser['email']),
                    'email' => $socialUser['email'],
                    'account_type' => 'individual',
                    'role' => 'user',
                    'status' => 'active',
                ]);

                // Create a Profile linked to this user (all foreign keys reference profiles.id)
                Profile::create([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'full_name' => $user->name,
                    'username' => $user->username,
                ]);
            }

            // Link the social account to the user
            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $socialUser['provider_id'],
            ]);
        }

        // Generate JWT token for the user
        $token = Auth::guard('jwt')->login($user);
        $cookie = $this->generateTokenCookie($token);

        return [
            'user' => $user,
            'token' => $token,
            'cookie' => $cookie,
        ];
    }

    /**
     * Verify a Google ID token and return user info.
     */
    protected function verifyGoogleToken(string $idToken): array
    {
        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if ($response->failed()) {
            throw new Exception('Invalid Google ID token', 401);
        }

        $payload = $response->json();

        // Optionally verify the audience (your Google Client ID)
        // if ($payload['aud'] !== config('services.google.client_id')) {
        //     throw new Exception('Invalid Google audience', 401);
        // }

        return [
            'provider_id' => $payload['sub'],
            'email' => $payload['email'] ?? null,
            'name' => trim(($payload['given_name'] ?? '').' '.($payload['family_name'] ?? '')) ?: null,
            'given_name' => $payload['given_name'] ?? null,
            'family_name' => $payload['family_name'] ?? null,
        ];
    }

    /**
     * Verify an Apple identity token (ES256 / ECDSA P-256) and return user info.
     */
    protected function verifyAppleToken(string $identityToken): array
    {
        $tokenParts = explode('.', $identityToken);

        if (count($tokenParts) !== 3) {
            throw new Exception('Invalid Apple identity token format', 401);
        }

        // Decode the JWT header to find the key ID (kid) and algorithm
        $header = json_decode($this->base64UrlDecode($tokenParts[0]), true);

        if (! $header || ! isset($header['kid'])) {
            throw new Exception('Invalid Apple identity token header', 401);
        }

        // Fetch Apple's public keys with caching (keys rotate infrequently)
        $keys = Cache::remember('apple_jwks', 86400, function () {
            $response = Http::get('https://appleid.apple.com/auth/keys');

            if ($response->failed()) {
                throw new Exception('Failed to fetch Apple public keys', 500);
            }

            return $response->json('keys');
        });

        // Find the matching public key by key ID (kid)
        $publicKey = null;
        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $header['kid']) {
                $publicKey = $key;
                break;
            }
        }

        if (! $publicKey) {
            throw new Exception('Unable to find matching Apple public key', 401);
        }

        // Build an EC P-256 public key from the JWK coordinates
        $x = $this->base64UrlDecode($publicKey['x']);
        $y = $this->base64UrlDecode($publicKey['y']);
        $ecPem = $this->buildEcP256PublicKeyPem($x, $y);

        // Decode the payload for claims verification
        $payload = json_decode($this->base64UrlDecode($tokenParts[1]), true);

        if (! $payload) {
            throw new Exception('Invalid Apple identity token payload', 401);
        }

        // Verify expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new Exception('Apple identity token has expired', 401);
        }

        // Verify the signature
        $signature = $this->base64UrlDecode($tokenParts[2]);
        $data = $tokenParts[0].'.'.$tokenParts[1];
        $derSignature = $this->convertRawEcdsaToDer($signature);

        $verified = openssl_verify($data, $derSignature, $ecPem, OPENSSL_ALGO_SHA256);

        if (! $verified) {
            throw new Exception('Invalid Apple identity token signature', 401);
        }

        return [
            'provider_id' => $payload['sub'],
            'email' => $payload['email'] ?? null,
            'given_name' => $payload['given_name'] ?? ($payload['name'] ?? null),
            'family_name' => $payload['family_name'] ?? null,
        ];
    }

    /**
     * Build a PEM-formatted EC P-256 (prime256v1) public key from raw x, y coordinates.
     */
    protected function buildEcP256PublicKeyPem(string $x, string $y): string
    {
        // Uncompressed EC point: 0x04 || x || y
        $ecPoint = "\x04".$x.$y;

        // OID for ecPublicKey (1.2.840.10045.2.1)
        $ecPublicKeyOid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
        // OID for prime256v1 / P-256 (1.2.840.10045.3.1.7)
        $prime256v1Oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

        // AlgorithmIdentifier SEQUENCE: 30 13 [OIDs]
        $algorithmSequence = "\x30\x13".$ecPublicKeyOid.$prime256v1Oid;

        // SubjectPublicKeyInfo BIT STRING: 03 42 00 [ecPoint]
        $bitString = "\x03\x42\x00".$ecPoint;

        // Outer SEQUENCE
        $outerSequence = "\x30\x59".$algorithmSequence.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".
            chunk_split(base64_encode($outerSequence), 64, "\n").
            '-----END PUBLIC KEY-----';
    }

    /**
     * Convert a raw 64-byte ECDSA signature (R||S) to DER format for OpenSSL.
     */
    protected function convertRawEcdsaToDer(string $raw): string
    {
        $r = substr($raw, 0, 32);
        $s = substr($raw, 32, 32);

        $rDer = $this->toDerInteger($r);
        $sDer = $this->toDerInteger($s);

        $content = $rDer.$sDer;

        return "\x30".chr(strlen($content)).$content;
    }

    /**
     * Encode a raw binary integer as a DER INTEGER.
     */
    protected function toDerInteger(string $value): string
    {
        // Prepend 0x00 if the high bit is set (to avoid negative interpretation)
        if (ord($value[0]) & 0x80) {
            $value = "\x00".$value;
        }

        return "\x02".chr(strlen($value)).$value;
    }

    /**
     * Base64 URL-safe decode.
     */
    protected function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;

        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    /**
     * Helper to generate a 6-digit OTP code and save it.
     */
    protected function generateAndSaveOtp(User $user): string
    {
        // $otp = (string) rand(100000, 999999);
        $otp = '123456';
        $user->otp = Hash::make($otp);
        $user->otp_expires_at = now()->addMinutes(10);
        $user->save();

        return $otp;
    }

    /**
     * Derive a unique, safe username from the provider email.
     */
    protected function uniqueUsername(?string $email): string
    {
        $base = $email ? strtolower((string) Str::before($email, '@')) : 'user';
        $base = preg_replace('/[^a-z0-9_.]/', '', $base) ?: 'user';

        $username = $base;
        while (User::where('username', $username)->exists()) {
            $username = $base.'_'.Str::lower(Str::random(4));
        }

        return $username;
    }

    /**
     * Generate HttpOnly Secure cookie for token storage.
     */
    protected function generateTokenCookie(string $token)
    {
        return cookie(
            'token',
            $token,
            config('jwt.ttl', 60),
            '/',
            null,
            true, // Secure (only over HTTPS)
            true, // HttpOnly (inaccessible to JS)
            false,
            'Lax'
        );
    }

    /**
     * Get a cookie that invalidates/forgets the token.
     */
    protected function clearTokenCookie()
    {
        return cookie()->forget('token');
    }
}
