<?php

namespace App\DTOs\AliExpress;

/**
 * Immutable payload returned by the AliExpress OAuth token endpoints
 * (/auth/token/create and /auth/token/refresh).
 */
final readonly class AliExpressTokenData
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public int $expiresIn,
        public ?int $refreshTokenValidSeconds,
        public ?string $memberId,
        public ?string $accountName,
        public ?string $accountEmail,
    ) {}

    /**
     * Build a DTO from the raw token endpoint response.
     *
     * AliExpress returns (defensively parsed, official sample):
     *   access_token, refresh_token, expires_in (seconds),
     *   refresh_expires_in (seconds), user_id/seller_id, account (string).
     *
     * Legacy variants still supported as fallbacks:
     *   refresh_token_valid_time (milliseconds), aliId/member_id,
     *   account: { login_id, email, ... }.
     */
    public static function fromResponse(array $response): self
    {
        $account = is_array($response['account'] ?? null) ? $response['account'] : [];

        // Current API returns refresh_expires_in in seconds; the legacy
        // refresh_token_valid_time was documented in milliseconds.
        $refreshValidSeconds = (int) ($response['refresh_expires_in'] ?? 0);
        if ($refreshValidSeconds <= 0) {
            $legacyMs = (int) ($response['refresh_token_valid_time'] ?? 0);
            if ($legacyMs > 0) {
                $refreshValidSeconds = (int) ($legacyMs / 1000);
            }
        }

        return new self(
            accessToken: (string) ($response['access_token'] ?? ''),
            refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            expiresIn: max(0, (int) ($response['expires_in'] ?? 0)),
            refreshTokenValidSeconds: $refreshValidSeconds > 0 ? $refreshValidSeconds : null,
            memberId: (string) ($response['aliId']
                ?? $response['member_id']
                ?? $response['user_id']
                ?? $response['seller_id']
                ?? '') ?: null,
            accountName: isset($account['login_id'])
                ? (string) $account['login_id']
                : (isset($response['login_id']) ? (string) $response['login_id'] : null),
            accountEmail: isset($account['email'])
                ? (string) $account['email']
                : (isset($response['email'])
                    ? (string) $response['email']
                    : (is_string($response['account'] ?? null) ? $response['account'] : null)),
        );
    }

    public function tokenExpiresAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable)->add(new \DateInterval("PT{$this->expiresIn}S"));
    }

    public function refreshTokenValidUntil(): ?\DateTimeImmutable
    {
        return $this->refreshTokenValidSeconds
            ? (new \DateTimeImmutable)->add(new \DateInterval("PT{$this->refreshTokenValidSeconds}S"))
            : null;
    }
}
