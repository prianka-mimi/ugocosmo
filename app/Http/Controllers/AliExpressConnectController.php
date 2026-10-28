<?php

namespace App\Http\Controllers;

use App\DTOs\AliExpress\AliExpressTokenData;
use App\Exceptions\AliExpress\OAuthException;
use App\Http\Requests\AliExpressConnectRequest;
use App\Http\Resources\AliExpressAccountResource;
use App\Models\AliExpressAccount;
use App\Models\User;
use App\Repositories\AliExpressAccountRepository;
use App\Services\AliExpressOAuthService;
use App\Services\AliExpressTokenManager;
use App\Support\ApiResponse;
use App\Traits\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AliExpressConnectController extends Controller
{
    use ResolvesAuthUser;

    public function __construct(
        protected AliExpressOAuthService $oauth,
        protected AliExpressTokenManager $tokens,
        protected AliExpressAccountRepository $accounts,
    ) {}

    /**
     * Step 1 — generate the AliExpress OAuth authorization URL.
     *
     * POST /api/aliexpress/connect
     */
    public function connect(AliExpressConnectRequest $request): JsonResponse
    {
        try {
            $user = $this->user();
            $region = $request->input('region', config('services.aliexpress.region', 'sg'));

            // One AliExpress account per seller: already connected → point at /reconnect.
            $existing = AliExpressAccount::byUser($user->id)->active()->first();
            if ($existing) {
                return ApiResponse::success(
                    ['connected' => true],
                    'AliExpress is already connected.'
                );
            }

            $appRedirect = $request->input('app_redirect');
            $state = $this->oauth->generateState($user->id, $region, 30, $appRedirect);

            $authUrl = $this->oauth->buildAuthorizationUrl($state, $region);

            return ApiResponse::success([
                'authorization_url' => $authUrl,
                'region' => $region,
                'already_connected' => false,
            ], 'AliExpress OAuth authorization URL generated. Open it in a browser to authorize.');
        } catch (Throwable $e) {
            Log::error('AliExpress connect failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Step 2 — OAuth callback (web route, AliExpress redirects the browser).
     *
     * GET /auth/aliexpress/callback
     */
    public function callback(Request $request)
    {
        $code = $request->input('code');
        $state = $request->input('state');

        try {
            if (! $code || ! $state) {
                return ApiResponse::error('Missing OAuth parameters (code, state).', 400);
            }

            // The state is a signed, expiring, single-use token that securely
            // binds this OAuth transaction to the Castilo user who started it.
            // The callback does not trust the browser session or any query
            // parameter supplied by the client (e.g. ?user_id=).
            $payload = $this->oauth->validateState($state);
            if (! $payload) {
                Log::error('AliExpress callback: invalid, expired or replayed OAuth state');

                return ApiResponse::error('OAuth state is invalid or expired. Please start the connection again.', 400);
            }

            $userId = (int) $payload['user_id'];

            // The region travels inside the signed state, so the callback
            // does not depend on the browser session being preserved.
            $region = $payload['region']
                ?? config('services.aliexpress.region', 'sg');

            $tokenData = $this->oauth->exchangeCode($code, $region);

            $account = $this->upsertAccount((int) $userId, $region, $tokenData);

            Log::info('AliExpress account connected', [
                'account_id' => $account->id,
                'user_id' => $userId,
                'member_id' => $account->aliexpress_member_id,
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

            // No app_redirect — show success page.
            $accountName = e($fresh->account_name);

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
                  <div class="title">AliExpress Connected!</div>
                  <div class="sub">Account: <strong>{$accountName}</strong><br>You can now import products into Castilo.</div>
                  <a href="castilo://aliexpress/connected?status=success&account_id={$fresh->id}" class="btn btn-primary">&#8592; Back to Castilo App</a>
                </div></body></html>
                HTML,
                200
            )->header('Content-Type', 'text/html');
        } catch (Throwable $e) {
            Log::error('AliExpress callback failed: '.$e->getMessage(), [
                'exception' => get_class($e),
            ]);

            $message = $e instanceof OAuthException
                ? $e->getMessage()
                : 'AliExpress authorization could not be completed. Please try again.';

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
                  <a href="castilo://aliexpress/error?status=error" class="btn btn-primary">&#8592; Back to Castilo App</a>
                </div></body></html>
                HTML,
                400
            )->header('Content-Type', 'text/html');
        }
    }

    /**
     * Check connection status.
     *
     * GET /api/aliexpress/accounts/check
     */
    public function check(): JsonResponse
    {
        try {
            $account = AliExpressAccount::byUser($this->user()->id)->active()->latest('connected_at')->first();

            return ApiResponse::success(
                ['connected' => (bool) $account],
                $account
                    ? 'AliExpress is connected.'
                    : 'AliExpress is not connected. Connect your account to start importing products.'
            );
        } catch (Throwable $e) {
            Log::error('AliExpress check failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Disable a connection (imported products are preserved).
     *
     * POST /api/aliexpress/disconnect
     */
    public function disconnect(Request $request): JsonResponse
    {
        try {
            $user = $this->user();

            // account_id is optional — fall back to any account for this user
            // (including previously disabled ones so reconnect→disconnect works).
            $accountId = (int) $request->input('account_id');
            $account = $accountId
                ? $this->accounts->findById($accountId, $user->id)
                : AliExpressAccount::withTrashed()->where('user_id', $user->id)->whereNull('deleted_at')->latest('connected_at')->first();

            // Also try soft-deleted if still not found (edge case: was disabled).
            if (! $account && ! $accountId) {
                $account = AliExpressAccount::withTrashed()->where('user_id', $user->id)->latest('connected_at')->first();
            }

            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 404);
            }

            $this->authorize('disconnect', $account);

            $this->accounts->disable($account);

            return ApiResponse::success([], 'AliExpress account disconnected successfully. Your imported products are preserved.');
        } catch (Throwable $e) {
            Log::error('AliExpress disconnect failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Generate a fresh authorization URL for reconnection.
     *
     * POST /api/aliexpress/reconnect
     */
    public function reconnect(AliExpressConnectRequest $request): JsonResponse
    {
        try {
            $user = $this->user();
            $region = $request->input('region', config('services.aliexpress.region', 'sg'));

            $appRedirect = $request->input('app_redirect');
            $state = $this->oauth->generateState($user->id, $region, 30, $appRedirect);

            // Reconnect re-authorizes the seller's EXISTING account row. The
            // callback matches it by member ID (returned by the token
            // response) and, when AliExpress omits the member ID, falls back
            // to this seller's single active account — so no duplicate row is
            // created (the upsert is scoped to the state-verified user_id).
            $existing = AliExpressAccount::byUser($user->id)->active()->first();

            return ApiResponse::success([
                'authorization_url' => $this->oauth->buildAuthorizationUrl($state, $region),
                'region' => $region,
                'already_connected' => $existing !== null,
            ], 'Reconnection authorization URL generated.');
        } catch (Throwable $e) {
            Log::error('AliExpress reconnect failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Manually refresh the connected account's token.
     * Auto-resolves the active account — no account ID needed in the URL.
     *
     * POST /api/aliexpress/refresh-token
     */
    public function refreshToken(): JsonResponse
    {
        try {
            $user = $this->user();
            $account = AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first();

            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 404);
            }

            $this->tokens->refresh($account);

            return ApiResponse::success([
                'account' => new AliExpressAccountResource($account->fresh()),
                'token_valid' => true,
            ], 'AliExpress token refreshed successfully.');
        } catch (Throwable $e) {
            Log::error('AliExpress token refresh failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    // ─── Protected helpers ─────────────────────────────────────────

    protected function upsertAccount(int $userId, string $region, AliExpressTokenData $tokenData): AliExpressAccount
    {
        $user = User::find($userId);
        $profile = $user?->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        // Match the seller's existing connection, preferring the exact
        // AliExpress member ID; when AliExpress does not return one, fall
        // back to the seller's single active account (one connection per
        // seller) so a reconnect updates rather than duplicates.
        $account = null;

        if ($tokenData->memberId) {
            $account = AliExpressAccount::withTrashed()
                ->where('profile_id', $profile->id)
                ->where('aliexpress_member_id', $tokenData->memberId)
                ->first();
        }

        if (! $account) {
            $account = AliExpressAccount::withTrashed()
                ->where('profile_id', $profile->id)
                ->whereNull('deleted_at')
                ->where('status', AliExpressAccount::STATUS_ACTIVE)
                ->orderByDesc('connected_at')
                ->first();
        }

        $data = [
            'aliexpress_member_id' => $tokenData->memberId,
            'account_name' => $tokenData->accountName ?? $tokenData->accountEmail,
            'access_token' => $tokenData->accessToken,
            'refresh_token' => $tokenData->refreshToken,
            'token_expires_at' => $tokenData->tokenExpiresAt(),
            'refresh_token_valid_until' => $tokenData->refreshTokenValidUntil(),
            'scopes' => null,
            'region' => $region,
            'status' => AliExpressAccount::STATUS_ACTIVE,
            'connected_at' => now(),
            'last_error' => null,
        ];

        if ($account) {
            $data['deleted_at'] = null;

            return $this->accounts->update($account, $data);
        }

        // Creating a NEW connection: enforce one AliExpress account per
        // seller at the application level as well as the DB level.
        $existingForProfile = AliExpressAccount::withTrashed()
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
