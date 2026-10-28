<?php

namespace App\DTOs\Etsy;

/**
 * Immutable payload returned by the Etsy OAuth token endpoint
 * (POST https://api.etsy.com/v3/public/oauth/token).
 *
 * Official response shape (developer.etsy.com → Authentication):
 *   access_token  — bears a numeric Etsy user_id prefix, e.g. "12345678.<token>"
 *   token_type    — always "Bearer"
 *   expires_in    — 3600 (1 hour)
 *   refresh_token — 90-day lifetime, NOT rotated on refresh
 *   scope         — space-delimited string of scopes actually granted
 */
final readonly class EtsyTokenData
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public int $expiresIn,
        public ?string $scope,
        public ?int $etsyUserId,
    ) {}

    public static function fromResponse(array $response): self
    {
        $accessToken = (string) ($response['access_token'] ?? '');

        return new self(
            accessToken: $accessToken,
            refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            expiresIn: max(0, (int) ($response['expires_in'] ?? 0)),
            scope: isset($response['scope']) ? (string) $response['scope'] : null,
            // The access token embeds the Etsy user_id as its numeric prefix
            // ("12345678.<token>"). This prefix is ONLY available from the
            // authorization code grant flow (official docs) — we parse it
            // here so the account row can be matched on reconnect.
            etsyUserId: self::parseEtsyUserId($accessToken),
        );
    }

    /**
     * Extract the numeric Etsy user_id prefix from an access token.
     */
    public static function parseEtsyUserId(string $accessToken): ?int
    {
        $prefix = strtok($accessToken, '.');

        return $prefix !== false && ctype_digit($prefix) ? (int) $prefix : null;
    }

    public function tokenExpiresAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable)->add(new \DateInterval("PT{$this->expiresIn}S"));
    }

    /**
     * Etsy refresh tokens are valid for 90 days from issuance (official
     * docs) and are NOT rotated on refresh — the original refresh token
     * keeps working until expiry.
     */
    public function refreshTokenValidUntil(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable)->add(new \DateInterval('P90D'));
    }
}
