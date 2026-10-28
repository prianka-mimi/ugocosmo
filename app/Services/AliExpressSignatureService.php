<?php

namespace App\Services;

/**
 * Single source of truth for the AliExpress Open Platform signature.
 *
 * VERIFIED against the current official AliExpress Open Platform docs
 * ("Signature algorithm", developer.alibaba.com treeId=727 articleId=120692):
 *   1. sort all request parameters (system + application, EXCLUDING `sign`)
 *      by parameter name in ASCII order
 *   2. concatenate each key+value pair with NO separators
 *   3. ADD THE API NAME IN FRONT of the concatenated string
 *      (official HTTP sample: /orders/{orderId}access_token…app_key…)
 *   4. HMAC-SHA256 the UTF-8 string with the App Secret
 *   5. uppercase hex digest
 *
 * Step 3 applies to EVERY method — dot-style Open Platform methods
 * (aliexpress.merchant.products.list) and REST-style paths
 * (/orders/{orderId}, /auth/token/create) alike.
 *
 * The sandbox verifier + `variants()` still expose the alternate encodings
 * (no-method-name, base64, MD5) so a signature produced by the AliExpress
 * console signature tool can be compared against every variant before
 * going live.
 */
class AliExpressSignatureService
{
    public const SIGN_METHOD = 'sha256';

    /**
     * The exact string that gets signed: the API name prepended to the
     * sorted, concatenated non-empty params (`sign` excluded).
     */
    public function stringToSign(array $params, ?string $method = null): string
    {
        $string = $method !== null && $method !== '' ? $method : '';

        foreach ($this->normalizedParams($params) as $key => $value) {
            $string .= $key.(string) $value;
        }

        return $string;
    }

    /**
     * The signature used on real requests: HMAC-SHA256, uppercase hex.
     */
    public function sign(array $params, ?string $secret = null, ?string $method = null): string
    {
        return strtoupper(hash_hmac('sha256', $this->stringToSign($params, $method), (string) $secret));
    }

    /**
     * Every supported signing variant, keyed by a stable name.
     * Provided for OFFLINE verification against the AliExpress console
     * signature tool — production always uses `sign()`.
     *
     * @return array<string, string> variant name => signature
     */
    public function variants(array $params, ?string $secret = null, ?string $method = null): array
    {
        $params = $this->normalizedParams($params);
        $prefix = $method !== null && $method !== '' ? $method : '';

        $withMethod = $prefix.$this->concat($params);
        $withoutMethod = $this->concat($params);
        $noSignMethod = $prefix.$this->concat(array_filter(
            $params,
            fn (string $key) => $key !== 'sign_method',
            ARRAY_FILTER_USE_KEY
        ));

        return [
            'sha256_hex' => strtoupper(hash_hmac('sha256', $withMethod, (string) $secret)),
            'sha256_hex_no_method' => strtoupper(hash_hmac('sha256', $withoutMethod, (string) $secret)),
            'sha256_hex_no_sign_method' => strtoupper(hash_hmac('sha256', $noSignMethod, (string) $secret)),
            'sha256_base64' => base64_encode(hash_hmac('sha256', $withMethod, (string) $secret, true)),
            'md5_hex' => strtoupper(md5($withMethod.(string) $secret)),
            'md5_hex_no_method' => strtoupper(md5($withoutMethod.(string) $secret)),
            'md5_base64' => base64_encode(md5($withMethod.(string) $secret, true)),
        ];
    }

    /**
     * Returns the name of the variant whose signature equals $expected,
     * or null when none match. Uses hash_equals for constant-time compare.
     */
    public function findMatchingVariant(array $params, string $expected, ?string $secret = null, ?string $method = null): ?string
    {
        foreach ($this->variants($params, $secret, $method) as $name => $signature) {
            if (hash_equals($expected, $signature)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Full structured payload used by the sandbox capture and the
     * `aliexpress:verify-signature` command.
     *
     * @return array{
     *   sign_method: string,
     *   params: array<string, mixed>,
     *   string_to_sign: string,
     *   computed_signature: string,
     *   expected_signature: string|null,
     *   matches_expected: bool|null,
     *   matched_variant: string|null,
     *   variants: array<string, string>
     * }
     */
    public function verificationPayload(array $params, ?string $secret = null, ?string $expectedSignature = null, ?string $method = null): array
    {
        $params = $this->normalizedParams($params);

        $signature = $this->sign($params, $secret, $method);
        $matchedVariant = $expectedSignature !== null
            ? $this->findMatchingVariant($params, $expectedSignature, $secret, $method)
            : null;

        return [
            'sign_method' => self::SIGN_METHOD,
            'params' => $params,
            'string_to_sign' => $this->stringToSign($params, $method),
            'computed_signature' => $signature,
            'expected_signature' => $expectedSignature,
            'matches_expected' => $expectedSignature !== null
                ? hash_equals($expectedSignature, $signature)
                : null,
            'matched_variant' => $matchedVariant,
            'variants' => $this->variants($params, $secret, $method),
        ];
    }

    /**
     * Sorted params, `sign` excluded, empty values dropped — exactly as
     * the production client signs them.
     */
    protected function normalizedParams(array $params): array
    {
        unset($params['sign']);

        ksort($params);

        return array_filter(
            $params,
            fn (mixed $value) => $value !== null && $value !== ''
        );
    }

    protected function concat(array $params): string
    {
        $string = '';
        foreach ($params as $key => $value) {
            $string .= $key.(string) $value;
        }

        return $string;
    }
}
