<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional location to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Merchant Center (Product Listings on Google)
    |--------------------------------------------------------------------------
    |
    | Push products to Google Shopping / Google Search via the Merchant API.
    | Products appear in free listings on Google Search, Shopping tab, Maps,
    | YouTube, Gemini, and Google Images.
    |
    | Setup:
    |   1. Create a Google Merchant Center account at merchantcenter.google.com
    |   2. Verify your domain (admin.castilo.co.uk)
    |   3. Create an API data source in Merchant Center
    |   4. Create a Google Cloud service account with Merchant API access:
    |      - Go to console.cloud.google.com → IAM → Service Accounts
    |      - Create a service account with 'Merchant API Admin' role
    |      - Generate a JSON key and save to storage/app/google-merchant-sa.json
    |   5. Add to .env:
    |
    |      GOOGLE_MERCHANT_ID=5842317717
    |      GOOGLE_MERCHANT_DATA_SOURCE_ID=your_data_source_id
    |      GOOGLE_MERCHANT_SERVICE_ACCOUNT_JSON=app/google-merchant-sa.json
    |      GOOGLE_MERCHANT_PRODUCT_DOMAIN=https://admin.castilo.co.uk
    */
    /*
    |--------------------------------------------------------------------------
    | Google Indexing API (JobPosting / Event URL notifications)
    |--------------------------------------------------------------------------
    |
    | Notifies Google to crawl or de-index a URL immediately.
    | Uses the same service account JSON as Google Merchant Center.
    |
    | Setup:
    |   1. Enable the "Indexing API" in Google Cloud Console.
    |   2. Add the service account email as an owner in Google Search Console
    |      (Search Console → Settings → Users and permissions → Add user).
    |   3. Add to .env:
    |
    |      GOOGLE_INDEXING_SERVICE_ACCOUNT_JSON=app/google-merchant-sa.json
    |      GOOGLE_INDEXING_SITE_URL=https://admin.castilo.co.uk
    */
    'google_indexing' => [
        'service_account_json' => env('GOOGLE_INDEXING_SERVICE_ACCOUNT_JSON', env('GOOGLE_MERCHANT_SERVICE_ACCOUNT_JSON')),
        'site_url' => env('GOOGLE_INDEXING_SITE_URL', env('APP_URL')),
    ],

    'google_merchant' => [
        'merchant_id' => env('GOOGLE_MERCHANT_ID', '5842317717'),
        'data_source_id' => env('GOOGLE_MERCHANT_DATA_SOURCE_ID'),
        'service_account_json' => env('GOOGLE_MERCHANT_SERVICE_ACCOUNT_JSON'),
        'product_domain' => env('GOOGLE_MERCHANT_PRODUCT_DOMAIN', 'https://admin.castilo.co.uk'),
        'content_language' => env('GOOGLE_MERCHANT_CONTENT_LANGUAGE', 'en'),
        'feed_label' => env('GOOGLE_MERCHANT_FEED_LABEL', 'US'),
        'timeout' => (int) env('GOOGLE_MERCHANT_TIMEOUT', 30),
        'connect_timeout' => (int) env('GOOGLE_MERCHANT_CONNECT_TIMEOUT', 10),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (FCM)
    |--------------------------------------------------------------------------
    |
    | Credentials for sending push notifications via FCM HTTP v1 API.
    | Used for mobile push (Android / iOS) via device tokens.
    |
    | Setup:
    |   1. Create a Firebase project and enable Cloud Messaging.
    |   2. Generate a service-account JSON key (Firebase Console → Project Settings
    |      → Service accounts → Generate new private key).
    |   3. Save the JSON file to `storage/app/firebase-service-account.json`.
    |   4. Set the project ID in your .env:
    |
    |      FCM_PROJECT_ID=your-firebase-project-id
    |      FCM_CREDENTIALS_PATH=storage/app/firebase-service-account.json
    |
    | The PushNotificationService will auto-generate OAuth2 tokens from the
    | service-account JSON and cache them for 55 minutes (tokens expire in 1 hour).
    */
    'firebase' => [
        'project_id' => env('FCM_PROJECT_ID', env('FIREBASE_PROJECT_ID')),
        'credentials_path' => (function () {
            $path = env('FCM_CREDENTIALS_PATH', env('FCM_CREDENTIALS'));
            if (! $path) {
                return storage_path('app/firebase-service-account.json');
            }

            return (str_starts_with($path, '/') || str_starts_with($path, '\\') || (strlen($path) > 1 && $path[1] === ':'))
                ? $path
                : base_path($path);
        })(),
    ],

    /*
    |--------------------------------------------------------------------------
    | VAPID (Web Push / Browser Notifications)
    |--------------------------------------------------------------------------
    |
    | VAPID keys for sending browser push notifications via the Web Push API.
    | Used for browser-based push (Chrome, Firefox, Edge, Safari).
    |
    | Unlike FCM (which goes through Google's servers), Web Push is an open
    | standard that works with any browser that supports the Push API.
    |
    | Setup:
    |   1. Generate VAPID keys:
    |        php -r "print_r(\App\Manager\WebPushNotificationService::generateVapidKeys());"
    |      Or use the console command:
    |        php artisan vapid:generate-keys
    |
    |   2. Add the generated keys to your .env:
    |
    |      VAPID_PUBLIC_KEY=BGxs... (the public key)
    |      VAPID_PRIVATE_KEY=Qf8k... (the private key)
    |      VAPID_SUBJECT=mailto:admin@projectname.com
    |        (or https://yourdomain.com — used as contact by push services)
    |
    |   3. The front-end JS uses the PUBLIC key to subscribe:
    |      const vapidPublicKey = '{{VAPID_PUBLIC_KEY}}';
    |      registration.pushManager.subscribe({
    |          userVisibleOnly: true,
    |          applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
    |      }).then(sub => fetch('/api/push-subscriptions', {
    |          method: 'POST',
    |          body: JSON.stringify(sub.toJSON())
    |      }));
    |
    | Need to convert the base64 VAPID public key to Uint8Array for the browser:
    |   function urlBase64ToUint8Array(base64String) {
    |       const padding = '='.repeat((4 - base64String.length % 4) % 4);
    |       const base64 = (base64String + padding).replace(/\\-/g, '+').replace(/_/g, '/');
    |       const rawData = atob(base64);
    |       return Uint8Array.from([...rawData].map(ch => ch.charCodeAt(0)));
    |   }
    */
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],

    'stripe' => [
        'key' => env('STRIPE_PUBLIC') ?? env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shopify (OAuth Product Import)
    |--------------------------------------------------------------------------
    |
    | OAuth credentials from the Shopify Partner Dashboard. Used to connect
    | merchant stores via OAuth and import products into the local marketplace.
    |
    | Setup:
    |   1. Create an app in the Shopify Partner Dashboard.
    |   2. Set App URL to your app's domain and add the redirect URI.
    |   3. Copy the API Key and API Secret from the app's credentials page.
    |   4. Add the following to your .env:
    | API docs: https://shopify.dev/docs/api/admin-rest
    */
    'shopify' => [
        // NEVER hardcode credentials here — they are secrets.
        // Set them only in .env (gitignored):
        //   SHOPIFY_CLIENT_ID, SHOPIFY_CLIENT_SECRET, SHOPIFY_API_VERSION,
        //   SHOPIFY_REDIRECT_URI, SHOPIFY_SCOPES
        'api_key' => env('SHOPIFY_CLIENT_ID'),
        'api_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2024-04'),
        'redirect_uri' => env('SHOPIFY_REDIRECT_URI'),
        'scopes' => env('SHOPIFY_SCOPES', 'read_products,write_products,read_inventory,write_inventory,read_locations,read_files,write_files,read_orders,write_orders'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AliExpress Open Platform (OAuth Product Import)
    |--------------------------------------------------------------------------
    |
    | Credentials from the AliExpress Open Platform developer console
    | (https://open.aliexpress.com → Create App → Seller / Merchant app).
    |
    | AliExpress is used ONLY as a supplier: sellers connect their own
    | AliExpress account, import products as marketplace listings, and
    | manually purchase/receive stock. Customer payments never touch
    | AliExpress — no checkout/payment APIs are used.
    |
    | Setup:
    |   1. Register an app at open.aliexpress.com (choose the Merchant app
    |      type so `aliexpress.merchant.products.*` APIs are approved).
    |   2. Set the redirect URI in the console to match ALIEXPRESS_REDIRECT_URI.
    |   3. Add the following to your .env (gitignored):
    |
    |      ALIEXPRESS_APP_KEY=your_app_key
    |      ALIEXPRESS_APP_SECRET=your_app_secret
    |      ALIEXPRESS_REDIRECT_URI=https://yourdomain.com/auth/aliexpress/callback
    |      ALIEXPRESS_REGION=sg            # sg (api-sg.aliexpress.com) | global (api.aliexpress.com)
    |      ALIEXPRESS_DEFAULT_CURRENCY=USD
    |
    | IMPORTANT: API method names (`aliexpress.merchant.products.*`) and
    | response shapes vary by app tier. Verify the methods below are approved
    | for your app in the Open Platform console before going live.
    */
    /*
    |--------------------------------------------------------------------------
    | ShipEngine / ShipStation API (Shipping & Logistics)
    |--------------------------------------------------------------------------
    |
    | ShipEngine is the marketplace's shipping/logistics layer. It is a
    | third-party external service — Flutter/browser clients NEVER talk to it
    | directly; every request goes through ShipEngineApiClient server-side.
    |
    | NOTE: ShipEngine is currently being rebranded as ShipStation API. The
    | existing ShipEngine v1 API (https://api.shipengine.com/v1) remains the
    | current supported contract and is what this integration uses.
    |
    | Setup:
    |   1. Sign up at shipengine.com and generate an API key (sandbox or
    |      production). A sandbox key never charges real money and is
    |      suitable for development/testing.
    |   2. Add the following to your .env (gitignored):
    |
    |      SHIPENGINE_API_KEY=your_production_key
    |      SHIPENGINE_BASE_URL=https://api.shipengine.com
    |      SHIPENGINE_SANDBOX_MODE=false
    |      SHIPENGINE_SANDBOX_API_KEY=your_sandbox_key
    |      SHIPENGINE_TIMEOUT=30
    |      SHIPENGINE_MAX_RETRIES=3
    |      SHIPENGINE_RETRY_DELAY=2
    |      SHIPENGINE_WEIGHT_UNIT=ounce
    |      SHIPENGINE_SHIP_FROM='{"name":"Your Store","line1":"4301 Bull Creek Rd","city":"Austin","state":"TX","postal_code":"78731","country":"US","phone":"+1 555-555-5555"}'
    |
    | EU customers may set SHIPENGINE_BASE_URL=https://api.eu.shipengine.com.
    |
    | IMPORTANT: SHIPENGINE_SHIP_FROM is a JSON-encoded object of the
    | seller's structured ship-from address (ISO 3166-1 alpha-2 country).
    | The products.ship_from_address column is free text and cannot be
    | parsed reliably, so the ship-from origin is configured here instead.
    */
    'shipengine' => [
        // NEVER hardcode credentials here — they are secrets.
        'api_key' => env('SHIPENGINE_API_KEY'),
        'base_url' => env('SHIPENGINE_BASE_URL', 'https://api.shipengine.com'),
        'timeout' => (int) env('SHIPENGINE_TIMEOUT', 30),
        'connect_timeout' => (int) env('SHIPENGINE_CONNECT_TIMEOUT', 5),
        // Retries are applied to idempotent reads only (rates, tracking,
        // carriers, address validation). Label purchase is never retried
        // automatically.
        'max_retries' => (int) env('SHIPENGINE_MAX_RETRIES', 3),
        'retry_delay_seconds' => (int) env('SHIPENGINE_RETRY_DELAY', 2),
        'sandbox_mode' => (bool) env('SHIPENGINE_SANDBOX_MODE', false),
        'sandbox_api_key' => env('SHIPENGINE_SANDBOX_API_KEY'),
        'mock_mode' => (bool) env('SHIPENGINE_MOCK_MODE', false),
        'carrier_ids' => array_filter(array_map('trim', explode(',', (string) env('SHIPENGINE_CARRIER_IDS', 'se-360748,se-360749,se-360750,se-360751,se-360752,se-399629,se-399630')))),
        // Default weight fallback when a product lacks an explicit weight
        'default_weight_value' => (float) env('SHIPENGINE_DEFAULT_WEIGHT_VALUE', 1.0),
        'default_weight_unit' => env('SHIPENGINE_DEFAULT_WEIGHT_UNIT', 'pound'),
        // Product weight unit (products.weight has no unit column):
        // ounce | pound | gram | kilogram
        'weight_unit' => env('SHIPENGINE_WEIGHT_UNIT', env('SHIPENGINE_DEFAULT_WEIGHT_UNIT', 'pound')),
        // Default package dimensions fallback when a product lacks package_dimensions
        'default_dimensions' => [
            'length' => (float) env('SHIPENGINE_DEFAULT_LENGTH', 10.0),
            'width' => (float) env('SHIPENGINE_DEFAULT_WIDTH', 5.0),
            'height' => (float) env('SHIPENGINE_DEFAULT_HEIGHT', 5.0),
            'unit' => env('SHIPENGINE_DEFAULT_DIMENSION_UNIT', 'inch'),
        ],
        'ship_from' => (function () {
            $json = env('SHIPENGINE_SHIP_FROM');
            if (! $json) {
                return [];
            }
            $decoded = json_decode($json, true);

            return is_array($decoded) ? $decoded : [];
        })(),
        // How often the scheduled command re-syncs tracking status.
        'tracking_sync_minutes' => (int) env('SHIPENGINE_TRACKING_SYNC_MINUTES', 180),
        // Tracking webhook authentication. When SHIPENGINE_WEBHOOK_SECRET is
        // set, incoming POST /api/webhooks/shipengine/tracking calls must also
        // carry this value in the configured header as a development fallback
        // (the primary verification is RSA-SHA256 via ShipstationWebhookVerifier).
        'webhook_secret' => env('SHIPENGINE_WEBHOOK_SECRET'),
        'webhook_header' => env('SHIPENGINE_WEBHOOK_HEADER', 'X-ShipEngine-Signature'),
        // RSA-SHA256 webhook signature verification (official ShipStation
        // mechanism). The public key is fetched from the JWKS endpoint and
        // cached. A shared-secret fallback (above) covers local testing.
        'jwks_url' => env('SHIPSTATION_JWKS_URL', 'https://api.shipengine.com/jwks'),
        'webhook_timestamp_tolerance' => (int) env('SHIPENGINE_WEBHOOK_TIMESTAMP_TOLERANCE', 300),
        'jwks_cache_ttl' => (int) env('SHIPENGINE_JWKS_CACHE_TTL', 86400),
    ],

    'aliexpress' => [
        // NEVER hardcode credentials here — they are secrets.
        'app_key' => env('ALIEXPRESS_APP_KEY'),
        'app_secret' => env('ALIEXPRESS_APP_SECRET'),
        'redirect_uri' => env('ALIEXPRESS_REDIRECT_URI'),
        'region' => env('ALIEXPRESS_REGION', 'sg'),
        'api_version' => env('ALIEXPRESS_API_VERSION', '2.0'),
        'default_currency' => env('ALIEXPRESS_DEFAULT_CURRENCY', 'USD'),
        'default_condition' => env('ALIEXPRESS_DEFAULT_CONDITION', 'new'),
        'default_margin' => (float) env('ALIEXPRESS_DEFAULT_MARGIN', 20), // default profit margin %
        'low_stock_threshold' => (int) env('ALIEXPRESS_LOW_STOCK_THRESHOLD', 5),
        'max_import_retries' => (int) env('ALIEXPRESS_MAX_IMPORT_RETRIES', 3),
        'retry_delay_seconds' => (int) env('ALIEXPRESS_RETRY_DELAY_SECONDS', 2),
        'request_timeout' => (int) env('ALIEXPRESS_REQUEST_TIMEOUT', 30),
        // Sandbox / signature-verification mode (docs/aliexpress-integration.md §9):
        // requests are signed and captured (aliexpress_api_logs is_sandbox=1 +
        // aliexpress-sandbox log) but NEVER sent to AliExpress.
        'sandbox_mode' => (bool) env('ALIEXPRESS_SANDBOX_MODE', false),
        // Optional secret override for sandbox verification runs — defaults to app_secret.
        'sandbox_secret' => env('ALIEXPRESS_SANDBOX_SECRET'),
        // Endpoints (per region). Override only if AliExpress changes them.
        //
        // IMPORTANT: the current AliExpress Open Platform operates a single
        // oversea environment — api-sg.aliexpress.com (official "Seller
        // authorization introduction" and "API endpoint URLs" docs). The
        // legacy api.aliexpress.com host does NOT serve oversea seller-app
        // OAuth and redirects the browser to maintain.html?app=…-500, so the
        // 'global' region resolves to the same api-sg gateway. If AliExpress
        // ever reintroduces a distinct global host, override via
        // ALIEXPRESS_BASE_URI_GLOBAL without code changes.
        'base_uris' => [
            'sg' => env('ALIEXPRESS_BASE_URI_SG', 'https://api-sg.aliexpress.com'),
            'global' => env('ALIEXPRESS_BASE_URI_GLOBAL', 'https://api-sg.aliexpress.com'),
        ],
        // OAuth authorization endpoints (per region). These can differ from the
        // API base URIs — some seller/merchant apps must use open.aliexpress.com
        // or auth.aliexpress.com instead of api-*.aliexpress.com for the
        // authorization page. Override per region if needed.
        'oauth_base_uris' => [
            'sg' => env('ALIEXPRESS_OAUTH_BASE_URI_SG', 'https://api-sg.aliexpress.com'),
            'global' => env('ALIEXPRESS_OAUTH_BASE_URI_GLOBAL', 'https://api-sg.aliexpress.com'),
        ],
        // Open Platform API method names — confirm these are approved for
        // your app before going live (see docs/aliexpress-integration.md).
        'ship_to_country' => env('ALIEXPRESS_SHIP_TO_COUNTRY', 'US'),
        'methods' => [
            'product_detail' => 'aliexpress.ds.product.get',
            'product_search' => 'aliexpress.ds.product.search',
            // Real-time supplier stock per SKU (AE-Dropshipper API).
            'sku_inventory' => 'aliexpress.offer.ds.product.simplequery',
            // Shipping methods + freight cost for a destination country
            // (AE product API — aliexpress.social.product.freight.query).
            'freight_query' => 'aliexpress.social.product.freight.query',
            // Dropshipping (automated supplier ordering + tracking):
            'order_create' => 'aliexpress.ds.order.create',
            'order_get' => 'aliexpress.trade.ds.order.get',
        ],
        // Cache TTL for the search feed name list (changes rarely, is
        // rate-limited on AliExpress and identical for every seller).
        'feed_names_cache_ttl' => (int) env('ALIEXPRESS_FEED_NAMES_CACHE_TTL', 3600),
        // Seller authorization page (official docs — "Seller authorization
        // introduction", service address for the oversea environment):
        //   https://api-sg.aliexpress.com/oauth/authorize?response_type=code
        //   &force_auth=true&redirect_uri=...&client_id=...
        //
        // If your app shows only "Buyer login", try switching to the
        // open-platform authorization endpoint by setting:
        //   ALIEXPRESS_OAUTH_BASE_URI_SG=https://open.aliexpress.com
        'oauth_paths' => [
            'authorize' => '/oauth/authorize',
        ],
        // OAuth token methods are invoked through the /sync gateway (REST
        // style) — method name is part of the signed string (contains '/').
        'oauth_methods' => [
            'token_create' => '/auth/token/create',
            'token_refresh' => '/auth/token/refresh',
        ],
        'sync_path' => env('ALIEXPRESS_SYNC_PATH', '/sync'),
        'rest_path' => env('ALIEXPRESS_REST_PATH', '/sync'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Etsy Open API v3 (OAuth Product Import — same-to-same as AliExpress)
    |--------------------------------------------------------------------------
    |
    | Each seller connects their OWN Etsy account via OAuth 2.0 Authorization
    | Code + PKCE (official flow, developer.etsy.com → Authentication):
    |   Authorize: https://www.etsy.com/oauth/connect           (browser)
    |   Token:     https://api.etsy.com/v3/public/oauth/token   (POST, form)
    |
    | The platform keystring doubles as the OAuth client_id. Access tokens
    | live 1 hour; refresh tokens live 90 days and are NOT rotated.
    |
    | Setup:
    |   1. Create a developer app at https://www.etsy.com/developers/register
    |   2. Register the callback URL below EXACTLY (case-sensitive, https) in
    |      the Etsy developer console → Your Apps → Callback URL(s).
    |   3. Add the following to your .env (gitignored):
    |
    |      ETSY_API_KEY=your_keystring
    |      ETSY_REDIRECT_URI=https://yourdomain.com/auth/etsy/callback
    |
    | API docs: https://developer.etsy.com/documentation/essentials/authentication
    |
    | Rate limits: Etsy allows 100 requests/second per keystring and 10,000
    | requests/day; 429 responses are retried with linear backoff.
    */
    'etsy' => [
        // NEVER hardcode credentials here — they are secrets.
        'api_key' => env('ETSY_API_KEY'),
        // Shared secret from Your Apps (x-api-key format: keystring:shared_secret).
        'shared_secret' => env('ETSY_SHARED_SECRET'),
        // Registered callback URL — must match the Etsy console EXACTLY.
        'redirect_uri' => env('ETSY_REDIRECT_URI'),
        // Scopes requested during authorization (space-delimited). Public
        // browse needs none beyond the key; shops_r/listings_r cover the
        // seller-connected shop/listing reads used by the import flow.
        'scopes' => env('ETSY_SCOPES', 'shops_r listings_r'),
        'base_url' => env('ETSY_BASE_URL', 'https://openapi.etsy.com/v3/application'),
        'request_timeout' => (int) env('ETSY_REQUEST_TIMEOUT', 30),
        // Retries apply to idempotent reads only (listings/search/images).
        'max_retries' => (int) env('ETSY_MAX_RETRIES', 3),
        'retry_delay_seconds' => (int) env('ETSY_RETRY_DELAY_SECONDS', 2),
        // Max listings fetched per page (Etsy hard cap is 100).
        'page_limit' => (int) env('ETSY_PAGE_LIMIT', 100),
        // How often the scheduled command re-syncs imported listings.
        'sync_minutes' => (int) env('ETSY_SYNC_MINUTES', 120),
        'sync_lookback_minutes' => (int) env('ETSY_SYNC_LOOKBACK_MINUTES', 1440),
        // OAuth endpoints (official, per docs — override only if Etsy changes them).
        'oauth_authorize_url' => env('ETSY_OAUTH_AUTHORIZE_URL', 'https://www.etsy.com/oauth/connect'),
        'oauth_token_url' => env('ETSY_OAUTH_TOKEN_URL', 'https://api.etsy.com/v3/public/oauth/token'),
    ],

];
