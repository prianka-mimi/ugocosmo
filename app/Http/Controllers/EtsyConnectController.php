<?php

namespace App\Http\Controllers;

use App\DTOs\Etsy\EtsyTokenData;
use App\Exceptions\Etsy\EtsyOAuthException;
use App\Http\Requests\EtsyConnectRequest;
use App\Http\Resources\EtsyAccountResource;
use App\Models\EtsyAccount;
use App\Models\User;
use App\Repositories\EtsyAccountRepository;
use App\Services\EtsyOAuthService;
use App\Services\EtsyTokenManager;
use App\Support\ApiResponse;
use App\Traits\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Etsy OAuth connection endpoints — same-to-same flow as
 * AliExpressConnectController:
 *
 *   Step 1  POST /api/etsy/connect      → authorization URL (+ state, PKCE)
 *   Step 2  GET  /auth/etsy/callback    → browser redirect from Etsy.com
 *           POST /api/etsy/reconnect    → fresh authorization URL
 *           POST /api/etsy/disconnect   → disable (products preserved)
 *           GET  /api/etsy/account/status
 *           POST /api/etsy/refresh-token
 */
class EtsyConnectController extends Controller
{
    use ResolvesAuthUser;

    public function __construct(
        protected EtsyOAuthService $oauth,
        protected EtsyTokenManager $tokens,
        protected EtsyAccountRepository $accounts,
    ) {}

    /**
     * Step 1 — generate the Etsy OAuth authorization URL.
     *
     * POST /api/etsy/connect
     */
    public function connect(EtsyConnectRequest $request): JsonResponse
    {
        try {
            $user = $this->user();

            // One Etsy account per seller: already connected → say so.
            $existing = EtsyAccount::byUser($user->id)->active()->first();
            if ($existing) {
                return ApiResponse::success(
                    ['connected' => true],
                    'Etsy is already connected.'
                );
            }

            $appRedirect = $request->input('app_redirect');
            $state = $this->oauth->generateState($user->id, 30, $appRedirect);

            $authUrl = $this->oauth->buildAuthorizationUrl($state);

            return ApiResponse::success([
                'authorization_url' => $authUrl,
                'already_connected' => false,
            ], 'Etsy OAuth authorization URL generated. Open it in a browser to authorize.');
        } catch (Throwable $e) {
            Log::error('Etsy connect failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * Step 2 — OAuth callback (web route, Etsy redirects the browser).
     *
     * GET /auth/etsy/callback
     */
    public function callback(Request $request)
    {
        $code = $request->input('code');
        $state = $request->input('state');

        try {
            if (! $code || ! $state) {
                return ApiResponse::error('Missing OAuth parameters (code, state).', 400);
            }

            // Validate signature/expiry/single-use AND recover the Castilo
            // user + PKCE verifier bound to this transaction. The callback
            // never trusts the browser session or client-supplied identity.
            $payload = $this->oauth->validateState($state);
            if (! $payload) {
                Log::error('Etsy callback: invalid, expired or replayed OAuth state');

                return ApiResponse::error('OAuth state is invalid or expired. Please start the connection again.', 400);
            }

            $userId = (int) $payload['user_id'];

            $tokenData = $this->oauth->exchangeCode($code, $state);

            $account = $this->upsertAccount($userId, $tokenData);

            Log::info('Etsy account connected', [
                'account_id' => $account->id,
                'user_id' => $userId,
                'etsy_user_id' => $account->etsy_user_id,
            ]);

            $fresh = $account->fresh();
            $appRedirect = $payload['app_redirect'] ?? null;

            if ($appRedirect) {
                return redirect()->away($appRedirect.'?'.http_build_query([
                    'status' => 'success',
                    'account_id' => $fresh->id,
                    'account_name' => $fresh->account_name,
                ]));
            }

            // No app_redirect — show success page (same pattern as AliExpress).
            $accountName = e($fresh->account_name ?? 'Etsy account');

            return response(
                <<<HTML
                <!DOCTYPE html><html><head><meta charset="utf-8"><title>Connected</title>
                <meta name="viewport" content="width=device-width,initial-scale=1">
                <style>
                  body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5;}
                  .box{background:#fff;border-radius:12px;padding:48px 40px;text-align:center;box-shadow:0 2px 16px rgba(0,0,0,.1);max-width:420px;width:90%;}
                  .icon{font-size:56px;margin-bottom:16px;color:#28a745;}
                  .title{color:#28a745;font-size:22px;font-weight:700;margin-bottom:8px;}
                  .sub{color:#555;font-size:15px;margin-bottom:24px;}
                  .btn{display:inline-block;padding:12px 28px;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none;cursor:pointer;border:none;margin:6px;}
                  .btn-primary{background:#e8441a;color:#fff;}
                  .btn:hover{opacity:.88;}
                </style></head>
                <body><div class="box">
                  <div class="icon">&#10003;</div>
                  <div class="title">Etsy Connected!</div>
                  <div class="sub">Account: <strong>{$accountName}</strong><br>You can now import products into Castilo.</div>
                  <a href="castilo://etsy/connected?status=success&account_id={$fresh->id}" class="btn btn-primary">&#8592; Back to Castilo App</a>
                </div></body></html>
                HTML,
                200
            )->header('Content-Type', 'text/html');
        } catch (Throwable $e) {
            Log::error('Etsy callback failed: '.$e->getMessage(), [
                'exception' => get_class($e),
            ]);

            $message = $e instanceof EtsyOAuthException
                ? $e->getMessage()
                : 'Etsy authorization could not be completed. Please try again.';

            // If we have a state with app_redirect, send error back to app.
            $state = request()->input('state');
            if ($state) {
                $parts = explode('.', $state);
                if (count($parts) === 2) {
                    $decoded = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
                    $appRedirect = $decoded['app_redirect'] ?? null;
                    if ($appRedirect) {
                        return redirect()->away($appRedirect.'?'.http_build_query([
                            'status' => 'error',
                            'message' => $message,
                        ]));
                    }
                }
            }

            $safeMessage = e($message);

            return response(
                <<<HTML
                <!DOCTYPE html><html><head><meta charset="utf-8"><title>Connection Failed</title>
                <meta name="viewport" content="width=device-width,initial-scale=1">
                <style>
                  body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5;}
                  .box{background:#fff;border-radius:12px;padding:48px 40px;text-align:center;box-shadow:0 2px 16px rgba(0,0,0,.1);max-width:420px;width:90%;}
                  .icon{font-size:56px;margin-bottom:16px;color:#dc3545;}
                  .title{color:#dc3545;font-size:22px;font-weight:700;margin-bottom:8px;}
                  .sub{color:#555;font-size:15px;margin-bottom:24px;}
                  .btn{display:inline-block;padding:12px 28px;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none;cursor:pointer;border:none;margin:6px;}
                  .btn-primary{background:#e8441a;color:#fff;}
                  .btn:hover{opacity:.88;}
                </style></head>
                <body><div class="box">
                  <div class="icon">&#10007;</div>
                  <div class="title">Connection Failed</div>
                  <div class="sub">{$safeMessage}</div>
                  <a href="castilo://etsy/error?status=error" class="btn btn-primary">&#8592; Back to Castilo App</a>
                </div></body></html>
                HTML,
                400
            )->header('Content-Type', 'text/html');
        }
    }

    /**
     * Check connection status.
     *
     * GET /api/etsy/account/status
     */
    public function check(): JsonResponse
    {
        try {
            $account = EtsyAccount::byUser($this->user()->id)->active()->latest('connected_at')->first();

            return ApiResponse::success(
                ['connected' => (bool) $account],
                $account
                    ? 'Etsy is connected.'
                    : 'Etsy is not connected. Connect your account to start importing products.'
            );
        } catch (Throwable $e) {
            Log::error('Etsy check failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Disable a connection (imported products are preserved).
     *
     * POST /api/etsy/disconnect
     */
    public function disconnect(Request $request): JsonResponse
    {
        try {
            $user = $this->user();

            $accountId = (int) $request->input('account_id');
            $account = $accountId
                ? $this->accounts->findById($accountId, $user->id)
                : EtsyAccount::withTrashed()->where('user_id', $user->id)->whereNull('deleted_at')->latest('connected_at')->first();

            // Also try soft-deleted if still not found (edge case: was disabled).
            if (! $account && ! $accountId) {
                $account = EtsyAccount::withTrashed()->where('user_id', $user->id)->latest('connected_at')->first();
            }

            if (! $account) {
                return ApiResponse::error('No connected Etsy account found.', 404);
            }

            $this->authorize('disconnect', $account);

            $this->accounts->disable($account);

            return ApiResponse::success([], 'Etsy account disconnected successfully. Your imported products are preserved.');
        } catch (Throwable $e) {
            Log::error('Etsy disconnect failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Generate a fresh authorization URL for reconnection.
     *
     * POST /api/etsy/reconnect
     */
    public function reconnect(EtsyConnectRequest $request): JsonResponse
    {
        try {
            $user = $this->user();

            $appRedirect = $request->input('app_redirect');
            $state = $this->oauth->generateState($user->id, 30, $appRedirect);

            $existing = EtsyAccount::byUser($user->id)->active()->first();

            return ApiResponse::success([
                'authorization_url' => $this->oauth->buildAuthorizationUrl($state),
                'already_connected' => $existing !== null,
            ], 'Reconnection authorization URL generated.');
        } catch (Throwable $e) {
            Log::error('Etsy reconnect failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * Manually refresh the connected account's token.
     *
     * POST /api/etsy/refresh-token
     */
    public function refreshToken(): JsonResponse
    {
        try {
            $user = $this->user();
            $account = EtsyAccount::byUser($user->id)->active()->latest('connected_at')->first();

            if (! $account) {
                return ApiResponse::error('No connected Etsy account found.', 404);
            }

            $this->tokens->refresh($account);

            return ApiResponse::success([
                'account' => new EtsyAccountResource($account->fresh()),
                'token_valid' => true,
            ], 'Etsy token refreshed successfully.');
        } catch (Throwable $e) {
            Log::error('Etsy token refresh failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    // ─── Protected helpers ─────────────────────────────────────────

    protected function upsertAccount(int $userId, EtsyTokenData $tokenData): EtsyAccount
    {
        $user = User::find($userId);
        $profile = $user?->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        // Match the seller's existing connection by the Etsy user_id parsed
        // from the access token prefix; fall back to the seller's single
        // active account so a reconnect updates rather than duplicates.
        $account = null;

        if ($tokenData->etsyUserId) {
            $account = EtsyAccount::withTrashed()
                ->where('profile_id', $profile->id)
                ->where('etsy_user_id', (string) $tokenData->etsyUserId)
                ->first();
        }

        if (! $account) {
            $account = EtsyAccount::withTrashed()
                ->where('profile_id', $profile->id)
                ->whereNull('deleted_at')
                ->where('status', EtsyAccount::STATUS_ACTIVE)
                ->orderByDesc('connected_at')
                ->first();
        }

        $data = [
            'etsy_user_id' => $tokenData->etsyUserId ? (string) $tokenData->etsyUserId : null,
            'account_name' => $tokenData->etsyUserId ? ('Etsy user '.$tokenData->etsyUserId) : 'Etsy account',
            'access_token' => $tokenData->accessToken,
            'refresh_token' => $tokenData->refreshToken,
            'token_expires_at' => $tokenData->tokenExpiresAt(),
            'refresh_token_valid_until' => $tokenData->refreshTokenValidUntil(),
            'scopes' => $tokenData->scope,
            'status' => EtsyAccount::STATUS_ACTIVE,
            'connected_at' => now(),
            'last_error' => null,
        ];

        if ($account) {
            $data['deleted_at'] = null;

            return $this->accounts->update($account, $data);
        }

        // Creating a NEW connection: enforce one Etsy account per seller at
        // the application level as well as the DB level.
        $existingForProfile = EtsyAccount::withTrashed()
            ->where('profile_id', $profile->id)
            ->whereNull('deleted_at')
            ->first();

        if ($existingForProfile) {
            return $this->accounts->update($existingForProfile, array_merge($data, ['deleted_at' => null]));
        }

        return $this->accounts->create(array_merge($data, [
            'profile_id' => $profile->id,
            'user_id' => $userId,
        ]));
    }
}
