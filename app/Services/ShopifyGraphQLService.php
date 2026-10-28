<?php

namespace App\Services;

use App\Models\ShopifyStore;
use App\Repositories\ShopifyStoreRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyGraphQLService
{
    protected ?string $storeDomain = null;

    protected ?string $accessToken = null;

    protected int $maxRetries = 3;

    protected int $retryDelay = 1000; // ms — base for exponential backoff

    protected array $rateLimitState = [
        'remaining' => 40,
        'restore_rate' => 0.5, // per second
        'last_request' => 0,
        'current_max' => 40,
    ];

    public function __construct(
        protected ShopifyStoreRepository $storeRepository,
    ) {}

    /**
     * Configure the client for a specific store.
     */
    public function forStore(ShopifyStore $store): static
    {
        $this->storeDomain = $store->store_domain;
        $this->accessToken = $this->storeRepository->decryptToken($store);

        return $this;
    }

    /**
     * Configure with raw credentials.
     */
    public function withCredentials(string $domain, string $token): static
    {
        $this->storeDomain = $domain;
        $this->accessToken = $token;

        return $this;
    }

    /**
     * Execute a GraphQL query with automatic rate-limit handling.
     */
    public function query(string $query, array $variables = []): ?array
    {
        if (! $this->storeDomain || ! $this->accessToken) {
            Log::error('ShopifyGraphQLService: not configured.');

            return null;
        }

        $this->respectRateLimit();

        $domain = $this->normalizeDomain($this->storeDomain);
        $endpoint = "https://{$domain}/admin/api/{$this->getApiVersion()}/graphql.json";

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $this->accessToken,
                    'Content-Type' => 'application/json',
                ])->timeout(30)
                    ->post($endpoint, [
                        'query' => $query,
                        'variables' => $variables,
                    ]);

                $this->updateRateLimit($response->headers());

                if ($response->successful()) {
                    $body = $response->json();
                    if (! empty($body['errors'])) {
                        $this->handleGraphQLErrors($body['errors']);

                        return null;
                    }

                    return $body['data'] ?? [];
                }

                // Handle rate limit (429) or server errors
                if ($response->status() === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?? 2);
                    Log::warning("ShopifyGraphQL: rate limited, retrying after {$retryAfter}s (attempt {$attempt}/{$this->maxRetries})");
                    sleep($retryAfter);

                    continue;
                }

                if ($response->status() >= 500) {
                    $backoff = $this->retryDelay * pow(2, $attempt - 1);
                    Log::warning("ShopifyGraphQL: server error {$response->status()}, backing off {$backoff}ms (attempt {$attempt}/{$this->maxRetries})");
                    usleep($backoff * 1000);

                    continue;
                }

                Log::error('ShopifyGraphQL: request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;

            } catch (\Exception $e) {
                Log::error("ShopifyGraphQL: exception (attempt {$attempt}/{$this->maxRetries}): ".$e->getMessage());
                if ($attempt < $this->maxRetries) {
                    usleep($this->retryDelay * pow(2, $attempt - 1) * 1000);
                } else {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Fetch products with cursor-based pagination using GraphQL.
     */
    public function fetchProducts(int $first = 50, ?string $afterCursor = null): ?array
    {
        $query = <<<'GRAPHQL'
        query GetProducts($first: Int!, $after: String) {
            products(first: $first, after: $after) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        id
                        title
                        descriptionHtml
                        productType
                        vendor
                        status
                        tags
                        handle
                        createdAt
                        updatedAt
                        publishedAt
                        onlineStoreUrl
                        seo {
                            title
                            description
                        }
                        options {
                            name
                            values
                        }
                        featuredImage {
                            url
                            altText
                        }
                        images(first: 10) {
                            edges {
                                node {
                                    url
                                    altText
                                }
                            }
                        }
                        variants(first: 100) {
                            edges {
                                node {
                                    id
                                    title
                                    price
                                    compareAtPrice
                                    sku
                                    barcode
                                    weight
                                    weightUnit
                                    requiresShipping
                                    inventoryQuantity
                                    inventoryItem {
                                        id
                                    }
                                    image {
                                        src
                                    }
                                    selectedOptions {
                                        name
                                        value
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, [
            'first' => min($first, 250),
            'after' => $afterCursor,
        ]);

        if (! $data || ! isset($data['products'])) {
            return null;
        }

        $products = $data['products'];
        $nodes = [];

        foreach (($products['edges'] ?? []) as $edge) {
            $nodes[] = $edge['node'];
        }

        return [
            'products' => $nodes,
            'page_info' => $products['pageInfo'] ?? [],
            'has_next_page' => $products['pageInfo']['hasNextPage'] ?? false,
            'end_cursor' => $products['pageInfo']['endCursor'] ?? null,
        ];
    }

    /**
     * Fetch products filtered by a GraphQL query string.
     * Supports: status, updated_at, tag, title, vendor, product_type, etc.
     *
     * Example queries:
     *   "status:active"
     *   "updated_at:>=2024-01-01T00:00:00Z"
     *   "tag:electronics"
     *   "status:draft OR status:active"
     */
    public function fetchProductsByQuery(
        string $queryFilter,
        int $first = 50,
        ?string $afterCursor = null
    ): ?array {
        $query = <<<'GRAPHQL'
        query GetProductsByQuery($first: Int!, $after: String, $query: String!) {
            products(first: $first, after: $after, query: $query) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        id
                        title
                        descriptionHtml
                        productType
                        vendor
                        status
                        tags
                        handle
                        createdAt
                        updatedAt
                        publishedAt
                        onlineStoreUrl
                        seo {
                            title
                            description
                        }
                        options {
                            name
                            values
                        }
                        featuredImage {
                            url
                            altText
                        }
                        images(first: 10) {
                            edges {
                                node {
                                    url
                                    altText
                                }
                            }
                        }
                        variants(first: 100) {
                            edges {
                                node {
                                    id
                                    title
                                    price
                                    compareAtPrice
                                    sku
                                    barcode
                                    weight
                                    weightUnit
                                    requiresShipping
                                    inventoryQuantity
                                    inventoryItem {
                                        id
                                    }
                                    image {
                                        src
                                    }
                                    selectedOptions {
                                        name
                                        value
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, [
            'first' => min($first, 250),
            'after' => $afterCursor,
            'query' => $queryFilter,
        ]);

        if (! $data || ! isset($data['products'])) {
            return null;
        }

        $products = $data['products'];
        $nodes = [];

        foreach (($products['edges'] ?? []) as $edge) {
            $nodes[] = $edge['node'];
        }

        return [
            'products' => $nodes,
            'page_info' => $products['pageInfo'] ?? [],
            'has_next_page' => $products['pageInfo']['hasNextPage'] ?? false,
            'end_cursor' => $products['pageInfo']['endCursor'] ?? null,
        ];
    }

    /**
     * Fetch products from a specific collection.
     * Returns products that belong to the given collection ID.
     */
    public function fetchProductsByCollection(
        string $collectionId,
        int $first = 50,
        ?string $afterCursor = null
    ): ?array {
        // Convert numeric to GID if needed
        if (! str_contains($collectionId, 'gid://')) {
            $collectionId = "gid://shopify/Collection/{$collectionId}";
        }

        $query = <<<'GRAPHQL'
        query GetCollectionProducts($id: ID!, $first: Int!, $after: String) {
            collection(id: $id) {
                id
                title
                products(first: $first, after: $after) {
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                    edges {
                        node {
                            id
                            title
                            descriptionHtml
                            productType
                            vendor
                            status
                            tags
                            handle
                            createdAt
                            updatedAt
                            publishedAt
                            onlineStoreUrl
                            seo {
                                title
                                description
                            }
                            options {
                                name
                                values
                            }
                            featuredImage {
                                url
                                altText
                            }
                            images(first: 10) {
                                edges {
                                    node {
                                        url
                                        altText
                                    }
                                }
                            }
                            variants(first: 100) {
                                edges {
                                    node {
                                        id
                                        title
                                        price
                                        compareAtPrice
                                        sku
                                        barcode
                                        weight
                                        weightUnit
                                        requiresShipping
                                        inventoryQuantity
                                        inventoryItem {
                                            id
                                        }
                                        image {
                                            src
                                        }
                                        selectedOptions {
                                            name
                                            value
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, [
            'id' => $collectionId,
            'first' => min($first, 250),
            'after' => $afterCursor,
        ]);

        if (! $data || ! isset($data['collection']['products'])) {
            return null;
        }

        $products = $data['collection']['products'];
        $nodes = [];

        foreach (($products['edges'] ?? []) as $edge) {
            $nodes[] = $edge['node'];
        }

        return [
            'products' => $nodes,
            'collection_title' => $data['collection']['title'] ?? null,
            'page_info' => $products['pageInfo'] ?? [],
            'has_next_page' => $products['pageInfo']['hasNextPage'] ?? false,
            'end_cursor' => $products['pageInfo']['endCursor'] ?? null,
        ];
    }

    /**
     * Fetch products updated since a given timestamp (incremental sync).
     * Uses the GraphQL query: updated_at:>=<timestamp>
     */
    public function fetchProductsUpdatedSince(
        string $updatedSince,
        int $first = 50,
        ?string $afterCursor = null
    ): ?array {
        return $this->fetchProductsByQuery(
            "updated_at:>={$updatedSince}",
            $first,
            $afterCursor
        );
    }

    /**
     * Fetch products by status (active, draft, archived).
     */
    public function fetchProductsByStatus(
        string $status,
        int $first = 50,
        ?string $afterCursor = null
    ): ?array {
        return $this->fetchProductsByQuery(
            "status:{$status}",
            $first,
            $afterCursor
        );
    }

    /**
     * Fetch products by tag.
     */
    public function fetchProductsByTag(
        string $tag,
        int $first = 50,
        ?string $afterCursor = null
    ): ?array {
        return $this->fetchProductsByQuery(
            "tag:{$tag}",
            $first,
            $afterCursor
        );
    }

    /**
     * Fetch a single product by ID (GraphQL).
     */
    public function fetchProduct(string $productId): ?array
    {
        // Convert numeric ID to Shopify's gid format if needed
        if (! str_contains($productId, 'gid://')) {
            $productId = "gid://shopify/Product/{$productId}";
        }

        $query = <<<'GRAPHQL'
        query GetProduct($id: ID!) {
            product(id: $id) {
                id
                title
                descriptionHtml
                productType
                vendor
                status
                tags
                handle
                createdAt
                updatedAt
                publishedAt
                onlineStoreUrl
                seo {
                    title
                    description
                }
                options {
                    name
                    values
                }
                featuredImage {
                    url
                    altText
                }
                images(first: 10) {
                    edges {
                        node {
                            url
                            altText
                        }
                    }
                }
                variants(first: 100) {
                    edges {
                        node {
                            id
                            title
                            price
                            compareAtPrice
                            sku
                            barcode
                            weight
                            weightUnit
                            requiresShipping
                            inventoryQuantity
                            inventoryItem {
                                id
                            }
                            image {
                                src
                            }
                            selectedOptions {
                                name
                                value
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, ['id' => $productId]);

        return $data['product'] ?? null;
    }

    /**
     * Fetch product counts.
     */
    public function countProducts(): int
    {
        $query = <<<'GRAPHQL'
        query {
            productsCount {
                count
            }
        }
        GRAPHQL;

        $data = $this->query($query);

        return $data['productsCount']['count'] ?? 0;
    }

    /**
     * Verify the API connection by making a lightweight query.
     *
     * This is used by the import job to fail fast if the access token
     * has expired or is invalid, rather than failing per-product.
     *
     * Returns true if the store is reachable with the current token.
     */
    public function verifyConnection(): bool
    {
        $query = <<<'GRAPHQL'
        query {
            shop {
                name
            }
        }
        GRAPHQL;

        $result = $this->query($query);
        $connected = $result !== null && isset($result['shop']['name']);

        if (! $connected) {
            Log::warning('ShopifyGraphQL: Connection verification failed — token may be expired or invalid', [
                'store_domain' => $this->storeDomain,
            ]);
        }

        return $connected;
    }

    /**
     * Fetch collections (custom + smart).
     */
    public function fetchCollections(int $first = 50, ?string $afterCursor = null): ?array
    {
        $query = <<<'GRAPHQL'
        query GetCollections($first: Int!, $after: String) {
            collections(first: $first, after: $after) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        id
                        title
                        descriptionHtml
                        image {
                            url
                        }
                        collectionType
                        updatedAt
                    }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, [
            'first' => min($first, 250),
            'after' => $afterCursor,
        ]);

        if (! $data || ! isset($data['collections'])) {
            return null;
        }

        $collections = $data['collections'];
        $nodes = [];

        foreach (($collections['edges'] ?? []) as $edge) {
            $nodes[] = $edge['node'];
        }

        return [
            'collections' => $nodes,
            'page_info' => $collections['pageInfo'] ?? [],
            'has_next_page' => $collections['pageInfo']['hasNextPage'] ?? false,
            'end_cursor' => $collections['pageInfo']['endCursor'] ?? null,
        ];
    }

    /**
     * Fetch inventory levels for specific inventory item IDs.
     */
    public function fetchInventoryLevels(array $inventoryItemIds): ?array
    {
        $ids = array_map(fn ($id) => is_numeric($id) ? "gid://shopify/InventoryItem/{$id}" : $id, $inventoryItemIds);
        $idsParam = '["'.implode('","', $ids).'"]';

        $query = <<<GRAPHQL
        query {
            inventoryItems(first: 50, ids: {$idsParam}) {
                edges {
                    node {
                        id
                        sku
                        inventoryLevels(first: 10) {
                            edges {
                                node {
                                    available
                                    location {
                                        id
                                        name
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query);

        return $data['inventoryItems']['edges'] ?? [];
    }

    /**
     * Register a webhook subscription.
     */
    public function createWebhook(string $topic, string $callbackUrl): ?array
    {
        $query = <<<'GRAPHQL'
        mutation WebhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
            webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
                webhookSubscription {
                    id
                    topic
                    endpoint {
                        ... on WebhookHttpEndpoint {
                            callbackUrl
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, [
            'topic' => $topic,
            'webhookSubscription' => [
                'callbackUrl' => $callbackUrl,
                'format' => 'JSON',
            ],
        ]);

        return $data['webhookSubscriptionCreate'] ?? null;
    }

    /**
     * Delete a webhook subscription.
     */
    public function deleteWebhook(string $webhookId): ?array
    {
        $query = <<<'GRAPHQL'
        mutation WebhookSubscriptionDelete($id: ID!) {
            webhookSubscriptionDelete(id: $id) {
                deletedWebhookSubscriptionId
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, ['id' => $webhookId]);

        return $data['webhookSubscriptionDelete'] ?? null;
    }

    /**
     * List existing webhook subscriptions.
     */
    public function listWebhooks(int $first = 50): ?array
    {
        $query = <<<'GRAPHQL'
        query GetWebhooks($first: Int!) {
            webhookSubscriptions(first: $first) {
                edges {
                    node {
                        id
                        topic
                        endpoint {
                            ... on WebhookHttpEndpoint {
                                callbackUrl
                            }
                        }
                        createdAt
                    }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, ['first' => $first]);

        return $data['webhookSubscriptions']['edges'] ?? [];
    }

    // ─── Rate Limiting ─────────────────────────────────────────────

    /**
     * Respect Shopify's rate limits by delaying if needed.
     */
    protected function respectRateLimit(): void
    {
        if ($this->rateLimitState['remaining'] > 0) {
            return;
        }

        $elapsed = (microtime(true) - $this->rateLimitState['last_request']);
        $pointsRestored = $elapsed * $this->rateLimitState['restore_rate'];

        $this->rateLimitState['remaining'] = min(
            $this->rateLimitState['current_max'],
            $this->rateLimitState['remaining'] + (int) $pointsRestored
        );

        if ($this->rateLimitState['remaining'] <= 0) {
            $waitMs = (int) ((1 / $this->rateLimitState['restore_rate']) * 1000);
            Log::debug("ShopifyGraphQL: rate limit exhausted, waiting {$waitMs}ms");
            usleep($waitMs * 1000);
            $this->rateLimitState['remaining'] = 1;
        }
    }

    /**
     * Update rate limit state from response headers.
     */
    protected function updateRateLimit(array $headers): void
    {
        $header = fn ($key) => $headers[$key][0] ?? null;

        $remaining = $header('X-Shopify-Shop-Api-Call-Limit');
        if ($remaining) {
            $parts = explode('/', $remaining);
            $this->rateLimitState['remaining'] = (int) ($parts[0] ?? 40);
            $this->rateLimitState['current_max'] = (int) ($parts[1] ?? 40);
        }

        $this->rateLimitState['last_request'] = microtime(true);
    }

    /**
     * Handle GraphQL errors from the response.
     */
    protected function handleGraphQLErrors(array $errors): void
    {
        foreach ($errors as $error) {
            $message = $error['message'] ?? 'Unknown GraphQL error';
            $extensions = $error['extensions'] ?? [];

            if (isset($extensions['code']) && $extensions['code'] === 'THROTTLED') {
                $retryAfter = $extensions['retryAfter'] ?? 2;
                Log::warning("ShopifyGraphQL: throttled, retrying after {$retryAfter}s");
                sleep((int) $retryAfter);
            }

            Log::error("ShopifyGraphQL error: {$message}", $extensions);
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────

    protected function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        $domain = str_replace(['https://', 'http://'], '', $domain);
        $domain = rtrim($domain, '/');

        if (! str_contains($domain, 'myshopify.com') && ! str_contains($domain, '.')) {
            $domain = "{$domain}.myshopify.com";
        }

        return $domain;
    }

    protected function getApiVersion(): string
    {
        return config('services.shopify.api_version', '2024-04');
    }
}
