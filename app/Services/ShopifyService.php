<?php

namespace App\Services;

use App\Models\ShopifyStore;
use App\Repositories\ShopifyStoreRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyService
{
    protected string $apiKey;

    protected string $apiSecret;

    protected string $apiVersion;

    protected string $redirectUri;

    protected string $scopes;

    /** @var string|null Current store domain for API calls (set via forStore) */
    protected ?string $currentStoreDomain = null;

    /** @var string|null Current access token for API calls (set via forStore) */
    protected ?string $currentAccessToken = null;

    protected ShopifyStoreRepository $storeRepository;

    public function __construct()
    {
        $this->apiKey = config('services.shopify.api_key') ?? '';
        $this->apiSecret = config('services.shopify.api_secret') ?? '';
        $this->apiVersion = config('services.shopify.api_version', '2024-04');
        $this->redirectUri = config('services.shopify.redirect_uri') ?? '';
        $this->scopes = config('services.shopify.scopes', 'read_products,write_products');
        $this->storeRepository = app(ShopifyStoreRepository::class);
    }

    // ─── OAuth Flow ─────────────────────────────────────────────────

    /**
     * Generate a random state value for CSRF protection.
     */
    public function generateState(): string
    {
        return Str::random(32);
    }

    /**
     * Build the Shopify OAuth authorization URL for a given store.
     */
    public function buildAuthorizationUrl(string $storeDomain, string $state): string
    {
        $store = $this->normalizeDomain($storeDomain);

        $query = http_build_query([
            'client_id' => $this->apiKey,
            'scope' => $this->scopes,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
        ]);

        return "https://{$store}/admin/oauth/authorize?{$query}";
    }

    /**
     * Exchange the OAuth authorization code for a permanent access token.
     *
     * @return array{access_token: string, scope: string}|null
     */
    public function exchangeCode(string $storeDomain, string $code): ?array
    {
        $store = $this->normalizeDomain($storeDomain);

        try {
            $response = Http::timeout(15)
                ->post("https://{$store}/admin/oauth/access_token", [
                    'client_id' => $this->apiKey,
                    'client_secret' => $this->apiSecret,
                    'code' => $code,
                ]);

            if (! $response->successful()) {
                Log::error('ShopifyService: OAuth token exchange failed', [
                    'store' => $store,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('ShopifyService: OAuth token exchange exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Verify the HMAC signature from Shopify's callback request.
     */
    public function verifyHmac(array $params): bool
    {
        $hmac = $params['hmac'] ?? '';
        unset($params['hmac'], $params['signature']);

        ksort($params);
        $message = http_build_query($params);
        $computed = hash_hmac('sha256', $message, $this->apiSecret);

        return hash_equals($computed, $hmac);
    }

    // ─── Store-Specific API Calls ───────────────────────────────────

    /**
     * Set the service to use a specific connected store's credentials.
     *
     * NOTE: Access tokens are stored encrypted in the database.
     * This method decrypts the token before use, so REST API calls
     * send the actual decrypted token in the X-Shopify-Access-Token header.
     */
    public function forStore(ShopifyStore $store): static
    {
        $this->currentStoreDomain = $store->store_domain;
        $this->currentAccessToken = $this->storeRepository->decryptToken($store);

        return $this;
    }

    /**
     * Set credentials manually (for non-DB contexts).
     */
    public function withCredentials(string $domain, string $token): static
    {
        $this->currentStoreDomain = $domain;
        $this->currentAccessToken = $token;

        return $this;
    }

    /**
     * Check whether current store credentials are set.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->currentStoreDomain) && ! empty($this->currentAccessToken);
    }

    /**
     * Get the base URL for Shopify Admin REST API calls.
     */
    protected function baseUrl(): string
    {
        $store = $this->normalizeDomain($this->currentStoreDomain ?? '');

        return "https://{$store}/admin/api/{$this->apiVersion}";
    }

    /**
     * Get headers required for Shopify Admin API authentication.
     */
    protected function headers(): array
    {
        return [
            'X-Shopify-Access-Token' => $this->currentAccessToken ?? '',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Normalize a store domain to the myshopify.com format.
     */
    public function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        $domain = str_replace(['https://', 'http://'], '', $domain);
        $domain = rtrim($domain, '/');

        if (! str_contains($domain, 'myshopify.com') && ! str_contains($domain, '.')) {
            $domain = "{$domain}.myshopify.com";
        }

        return $domain;
    }

    /**
     * Extract the raw store name from a domain (e.g., 'mystore.myshopify.com' → 'mystore').
     */
    public function extractStoreName(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);

        return strstr($domain, '.', true) ?: $domain;
    }

    /**
     * Get the configured scopes string.
     */
    public function getScopes(): string
    {
        return $this->scopes;
    }

    /**
     * Get the current store's myshopify domain.
     */
    public function getStoreDomain(): string
    {
        return $this->currentStoreDomain ?? '';
    }

    /**
     * Verify that the store actually exists by making a lightweight API call.
     */
    public function verifyStore(string $storeDomain): bool
    {
        $store = $this->normalizeDomain($storeDomain);
        try {
            $response = Http::timeout(10)->get("https://{$store}/admin/oauth/access_scopes.json", [
                'client_id' => $this->apiKey,
                'client_secret' => $this->apiSecret,
            ]);

            // If we get a 200, the store exists (even if we don't have a token yet)
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    // ─── Product API Calls ──────────────────────────────────────────

    /**
     * Fetch a paginated list of products from the Shopify store.
     */
    public function getProducts(int $limit = 50, ?string $sinceId = null): array
    {
        if (! $this->isConfigured()) {
            Log::error('ShopifyService: not configured. Call forStore() or withCredentials() first.');

            return ['products' => [], 'next_page' => null];
        }

        $params = [
            'limit' => min($limit, 250),
            'status' => 'active',
        ];

        if ($sinceId) {
            $params['since_id'] = $sinceId;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(30)
                ->get($this->baseUrl().'/products.json', $params);

            if (! $response->successful()) {
                Log::error('ShopifyService: products fetch failed', [
                    'store' => $this->currentStoreDomain,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['products' => [], 'next_page' => null];
            }

            $body = $response->json();
            $products = $body['products'] ?? [];

            // Extract link header for pagination (since_id based)
            $nextPage = null;
            $linkHeader = $response->header('Link');
            if ($linkHeader && preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches)) {
                $nextUrl = $matches[1];
                parse_str(parse_url($nextUrl, PHP_URL_QUERY) ?? '', $queryParams);
                $nextPage = $queryParams['since_id'] ?? null;
            }

            // Fallback: manually determine next since_id from last product
            if (! $nextPage && count($products) === $limit) {
                $lastProduct = end($products);
                $nextPage = (string) ($lastProduct['id'] ?? null);
            }

            return [
                'products' => $products,
                'next_page' => $nextPage,
            ];
        } catch (\Exception $e) {
            Log::error('ShopifyService: products fetch exception: '.$e->getMessage());

            return ['products' => [], 'next_page' => null];
        }
    }

    /**
     * Fetch a single product by its Shopify ID.
     */
    public function getProduct(int $productId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(15)
                ->get($this->baseUrl()."/products/{$productId}.json");

            if (! $response->successful()) {
                Log::error('ShopifyService: single product fetch failed', [
                    'product_id' => $productId,
                    'store' => $this->currentStoreDomain,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json()['product'] ?? null;
        } catch (\Exception $e) {
            Log::error('ShopifyService: single product fetch exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Count total products available in the Shopify store.
     */
    public function countProducts(): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(10)
                ->get($this->baseUrl().'/products/count.json', ['status' => 'active']);

            if (! $response->successful()) {
                return 0;
            }

            return (int) ($response->json()['count'] ?? 0);
        } catch (\Exception $e) {
            Log::error('ShopifyService: product count exception: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Download a product image from Shopify and return the raw content.
     */
    public function downloadImage(string $url): ?string
    {
        try {
            $response = Http::timeout(15)->get($url);
            if ($response->successful()) {
                return $response->body();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('ShopifyService: image download failed: '.$e->getMessage());

            return null;
        }
    }
}
