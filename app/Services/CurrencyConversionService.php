<?php

namespace App\Services;

use App\Exceptions\CurrencyConversionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Currency conversion for supplier pricing.
 *
 * Converts AliExpress listing costs into the marketplace's listing currency
 * before margin math. Rates are cached for a day (FX moves slowly and the
 * upstream free API tolerates one call per day); a stale cache is always
 * preferred over failing a sync.
 */
class CurrencyConversionService
{
    public const CACHE_KEY = 'fx_rates_v1';

    public const CACHE_TTL_SECONDS = 86400;

    protected string $base;

    /** @var array<string, float>|null */
    protected ?array $rates = null;

    public function __construct(?string $base = null)
    {
        $this->base = strtoupper($base ?? (string) config('services.aliexpress.default_currency', 'USD'));
    }

    /**
     * Convert an amount from one currency to another.
     *
     * @throws CurrencyConversionException when no conversion is possible
     */
    public function convert(float $amount, string $from, ?string $to = null): float
    {
        $to = strtoupper($to ?? $this->base);
        $from = strtoupper($from);

        if ($from === $to) {
            return round($amount, 4);
        }

        $rate = $this->rate($from, $to);

        return round($amount * $rate, 4);
    }

    /**
     * The exchange rate from one currency to another.
     *
     * @throws CurrencyConversionException
     */
    public function rate(string $from, ?string $to = null): float
    {
        $to = strtoupper($to ?? $this->base);
        $from = strtoupper($from);

        if ($from === $to) {
            return 1.0;
        }

        $rates = $this->rates();

        $fromRate = $rates[$from] ?? null;
        $toRate = $rates[$to] ?? null;

        if ($fromRate === null || $toRate === null || $fromRate <= 0.0) {
            throw new CurrencyConversionException(
                "No exchange rate available for {$from} to {$to}."
            );
        }

        return $toRate / $fromRate;
    }

    /**
     * Rates relative to the configured base currency, cached for a day.
     *
     * Serves the previous day's cache if the upstream is unreachable —
     * a slightly stale rate must never break a product sync.
     *
     * @return array<string, float>
     */
    protected function rates(): array
    {
        if ($this->rates !== null) {
            return $this->rates;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && $cached !== []) {
            return $this->rates = $cached;
        }

        try {
            $response = Http::timeout(10)
                ->get('https://open.er-api.com/v6/latest/'.$this->base)
                ->throw();

            $payload = $response->json();
            $rates = $payload['rates'] ?? null;

            if (is_array($rates) && $rates !== [] && ($payload['result'] ?? '') === 'success') {
                $rates = array_map('floatval', $rates);
                Cache::put(self::CACHE_KEY, $rates, self::CACHE_TTL_SECONDS);

                return $this->rates = $rates;
            }

            Log::warning('CurrencyConversionService: unexpected FX payload', [
                'base' => $this->base,
                'keys' => array_keys(is_array($payload) ? $payload : []),
            ]);
        } catch (Throwable $e) {
            Log::warning('CurrencyConversionService: FX fetch failed', [
                'base' => $this->base,
                'error' => $e->getMessage(),
            ]);
        }

        throw new CurrencyConversionException(
            "Exchange rates for base {$this->base} are unavailable."
        );
    }
}
