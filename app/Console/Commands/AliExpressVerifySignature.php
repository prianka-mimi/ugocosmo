<?php

namespace App\Console\Commands;

use App\Services\AliExpressApiClient;
use App\Services\AliExpressSignatureService;
use Illuminate\Console\Command;

/**
 * Offline signature verifier for the AliExpress Open Platform.
 *
 * Builds the exact signed request the client would send (no network) and
 * prints the sorted params, the exact string-to-sign and the computed
 * signature. When an expected signature (from the AliExpress console
 * signature tool) is provided, every supported algorithm variant is
 * compared and the matching one is reported — so the exact algorithm can
 * be confirmed before going live.
 */
class AliExpressVerifySignature extends Command
{
    protected $signature = 'aliexpress:verify-signature
        {--method= : Open Platform method name (default: methods.product_list from config)}
        {--params= : JSON object of private params sent as param_json, e.g. {"current_page":1,"page_size":20}}
        {--session= : seller access token (real value only needed to check it stays out of logs)}
        {--timestamp= : fixed ms timestamp (defaults to now — use to reproduce an exact run)}
        {--expected-signature= : signature returned by the AliExpress signature tool}
        {--secret= : app secret override (defaults to sandbox_secret, then app_secret)}';

    protected $description = 'Build the exact signed AliExpress request offline and verify the signature algorithm';

    public function handle(AliExpressApiClient $client, AliExpressSignatureService $signatures): int
    {
        $method = (string) ($this->option('method') ?: config('services.aliexpress.methods.product_list', ''));
        $params = $this->decodeParams((string) $this->option('params'));

        if ($this->option('params') && $params === []) {
            return self::FAILURE;
        }
        $session = (string) $this->option('session');
        $expected = $this->option('expected-signature') ? (string) $this->option('expected-signature') : null;
        $secret = (string) ($this->option('secret')
            ?: config('services.aliexpress.sandbox_secret')
            ?: config('services.aliexpress.app_secret'));

        if ($method === '') {
            $this->error('No method resolved. Pass --method or configure services.aliexpress.methods.product_list.');

            return self::FAILURE;
        }

        if ($secret === '') {
            $this->error('No app secret available. Pass --secret or set ALIEXPRESS_APP_SECRET / ALIEXPRESS_SANDBOX_SECRET.');

            return self::FAILURE;
        }

        $signed = $client->buildPublicParams(
            $method,
            $params,
            $session !== '' ? $session : null,
            $this->option('timestamp') ? (string) $this->option('timestamp') : null
        );
        $verification = $signatures->verificationPayload($signed, $secret, $expected);

        $this->line('');
        $this->info('AliExpress signed request (built offline — NOT sent)');
        $this->line('────────────────────────────────────────────────────────');
        $this->line("method        : {$method}");
        $this->line("sign_method   : {$verification['sign_method']}");
        $this->line('app_key       : '.($signed['app_key'] ?? '(unset)'));
        $this->line('timestamp     : '.($signed['timestamp'] ?? '(unset)'));
        $this->line('session       : '.($signed['session'] ?? '(none)'));
        $this->line('param_json    : '.($signed['param_json'] ?? '(unset)'));

        $this->line('');
        $this->info('Sorted params used for signing (excluding sign):');
        foreach ($verification['params'] as $key => $value) {
            $this->line("  {$key} = ".(string) $value);
        }

        $this->line('');
        $this->info('String to sign (API name always prepended — official algorithm):');
        $this->line('  '.$verification['string_to_sign']);

        $this->line('');
        $this->info('Computed signature (sha256_hex):');
        $this->line('  '.$verification['computed_signature']);

        if ($expected !== null) {
            $this->line('');
            $this->info('Expected signature:');
            $this->line('  '.$expected);

            if ($verification['matches_expected'] === true) {
                $this->line('');
                $this->info('✔ Signature MATCHES — the current algorithm is correct.');

                return self::SUCCESS;
            }

            $matched = $verification['matched_variant'];
            $this->line('');
            $this->error('✘ Signature does NOT match the current algorithm.');
            $this->line('');
            $this->info('Variants compared (yours vs expected):');
            foreach ($verification['variants'] as $name => $signature) {
                $mark = $name === $matched ? '  ← MATCH' : '';
                $this->line("  {$name}: {$signature}{$mark}");
            }

            if ($matched !== null) {
                $this->line('');
                $this->warn("AliExpress expects variant [{$matched}]. Update AliExpressSignatureService::sign() "
                    .'to match and re-run this command to confirm.');

                return self::FAILURE;
            }

            $this->line('');
            $this->error('No variant matched. Verify the app secret and the params pasted into the AliExpress tool.');

            return self::FAILURE;
        }

        $this->line('');
        $this->warn('No --expected-signature given. Paste the string-to-sign and these params into the '
            .'AliExpress console signature tool, then re-run with --expected-signature=<result>.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeParams(string $json): array
    {
        if ($json === '') {
            return ['current_page' => 1, 'page_size' => 20];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $this->error('--params must be valid JSON.');

            return [];
        }

        return $decoded;
    }
}
