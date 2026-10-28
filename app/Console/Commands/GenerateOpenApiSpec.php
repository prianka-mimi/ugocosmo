<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class GenerateOpenApiSpec extends Command
{
    protected $signature = 'api:generate-openapi
                            {--output=public/openapi.json : Output file path}
                            {--format=json : Output format (json or yaml)}
                            {--title=UgoCosmo API : API title}
                            {--api-version=1.0.0 : API version}';

    protected $description = 'Generate OpenAPI 3.0 specification from Laravel routes for Apidog synchronization';

    // Map of route group prefixes to folder names for Apidog
    protected array $folderMap = [
        'shopify' => 'Shopify Integration',
        'aliexpress' => 'AliExpress Integration',
        'csv' => 'CSV Import',
        'products' => 'Products',
        'product-categories' => 'Product Categories',
        'services' => 'Services',
        'providers/availability' => 'Provider Availability',
        'jobs' => 'Jobs',
        'job-applications' => 'Jobs',
        'events' => 'Events',
        'tickets' => 'Events',
        'bookings' => 'Bookings',
        'connections' => 'Connections',
        'conversations' => 'Messages',
        'posts' => 'Posts',
        'stories' => 'Stories',
        'livestreams' => 'Livestreams',
        'cart' => 'Shopping Cart',
        'orders' => 'Orders & Payments',
        'payments' => 'Orders & Payments',
        'shipping' => 'ShipEngine Shipping',
        'reviews' => 'Reviews',
        'wallet' => 'Wallet',
        'escrow' => 'Escrow & Disputes',
        'payment-accounts' => 'Bank Accounts',
        'payout-accounts' => 'Bank Accounts',
        'saved-items' => 'Saved Items',
        'notifications' => 'Notifications',
        'notification-preferences' => 'Notifications',
        'device-tokens' => 'Device Tokens & Push',
        'push-subscriptions' => 'Device Tokens & Push',
        'settings' => 'Settings & Contact',
        'profile' => 'My Profile',
        'profiles' => 'Connections',
        'register' => 'Authentication',
        'login' => 'Authentication',
        'logout' => 'Authentication',
        'forget-password' => 'Authentication',
        'reset-password' => 'Authentication',
        'verify-otp' => 'Authentication',
        'resend-otp' => 'Authentication',
        'social-login' => 'Authentication',
        'refresh-token' => 'Authentication',
        'password-change' => 'Authentication',
        'email-change' => 'Authentication',
        'countries' => 'Public',
        'service-categories' => 'Public',
        'product-categories' => 'Public',
        'faqs' => 'Public',
        'app-settings' => 'Public',
        'homepage' => 'Discover',
        'search' => 'Discover',
        'users' => 'Discover',
        'user' => 'Discover',
        'contact' => 'Settings & Contact',
        'debug-db' => 'Settings & Contact',
    ];

    public function handle(): int
    {
        $routes = $this->getApiRoutes();

        if (empty($routes)) {
            $this->error('No API routes found.');

            return self::FAILURE;
        }

        $spec = $this->buildOpenApiSpec($routes);

        $outputPath = $this->option('output');
        $content = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }

        file_put_contents($outputPath, $content);

        $this->info("OpenAPI spec generated: {$outputPath}");
        $this->info('Total endpoints: '.count($spec['paths']));

        // Summary by folder
        $folders = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $details) {
                $folder = $details['x-apidog-folder'] ?? 'Other';
                $folders[$folder] = ($folders[$folder] ?? 0) + 1;
            }
        }
        ksort($folders);
        $this->newLine();
        $this->info('Endpoints by folder:');
        foreach ($folders as $folder => $count) {
            $this->line("  {$folder}: {$count}");
        }

        return self::SUCCESS;
    }

    protected function getApiRoutes(): array
    {
        $routes = [];
        $routeCollection = Route::getRoutes();

        foreach ($routeCollection->getRoutes() as $route) {
            // Skip non-API routes and HEAD routes
            $methods = array_diff($route->methods(), ['HEAD']);
            if (empty($methods)) {
                continue;
            }

            $uri = $route->uri();

            // Only include /api/ routes
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            // Skip webhooks (not standard auth endpoints)
            if (str_starts_with($uri, 'api/webhooks/') || str_starts_with($uri, 'api/stripe/')) {
                continue;
            }

            // Strip /api/ prefix for cleaner paths
            $path = '/'.ltrim(substr($uri, 4), '/');

            // Skip debug endpoints
            if (str_contains($path, 'debug-db')) {
                continue;
            }

            $name = $route->getName();
            $action = $route->getAction();
            $controller = $action['controller'] ?? null;
            $middleware = $route->gatherMiddleware();

            $routes[] = [
                'methods' => array_map('strtolower', $methods),
                'path' => $path,
                'name' => $name,
                'controller' => $controller,
                'middleware' => $middleware,
                'parameters' => $route->parameterNames(),
            ];
        }

        return $routes;
    }

    protected function buildOpenApiSpec(array $routes): array
    {
        $paths = [];
        $tags = [];

        foreach ($routes as $route) {
            $path = $this->normalizePath($route['path']);
            $folder = $this->resolveFolder($path, $route['controller']);
            $tags[$folder] = true;

            foreach ($route['methods'] as $method) {
                $operationId = $this->generateOperationId($method, $path);
                $summary = $this->generateSummary($method, $path);

                $endpoint = [
                    'summary' => $summary,
                    'operationId' => $operationId,
                    'tags' => [$folder],
                    'x-apidog-folder' => $folder,
                    'parameters' => $this->buildParameters($route),
                    'responses' => [
                        '200' => [
                            'description' => 'Success',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'boolean'],
                                            'status_code' => ['type' => 'integer'],
                                            'status_message' => ['type' => 'string'],
                                            'data' => ['type' => 'object'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];

                // Add request body for POST/PUT/PATCH
                if (in_array($method, ['post', 'put', 'patch'])) {
                    $endpoint['requestBody'] = [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                ],
                            ],
                        ],
                    ];
                }

                if (! isset($paths[$path])) {
                    $paths[$path] = [];
                }

                $paths[$path][$method] = $endpoint;
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $this->option('title'),
                'version' => $this->option('api-version'),
                'description' => 'Auto-generated from Laravel routes. Use Merge import strategy in Apidog to preserve existing configuration.',
            ],
            'servers' => [
                [
                    'url' => '{{base_url}}',
                    'description' => 'API Base URL',
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => 'Use {{token}} variable in Apidog',
                    ],
                ],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
            'tags' => array_map(fn ($tag) => ['name' => $tag], array_keys($tags)),
            'paths' => $paths,
        ];
    }

    protected function normalizePath(string $path): string
    {
        // Convert Laravel route parameters {param} to OpenAPI style {param}
        $path = preg_replace('/\{(\w+)\}/', '{$1}', $path);

        // Ensure leading slash
        return '/'.ltrim($path, '/');
    }

    protected function resolveFolder(string $path, ?string $controller): string
    {
        // Try to match based on path prefix
        $segments = array_filter(explode('/', trim($path, '/')));

        if (! empty($segments)) {
            $prefix = $segments[0];

            if (isset($this->folderMap[$prefix])) {
                return $this->folderMap[$prefix];
            }

            // Check for nested prefixes (e.g., providers/availability)
            if (count($segments) > 1) {
                $nested = $segments[0].'/'.$segments[1];
                if (isset($this->folderMap[$nested])) {
                    return $this->folderMap[$nested];
                }
            }
        }

        // Try to resolve from controller name
        if ($controller) {
            $controllerClass = class_basename(explode('@', $controller)[0]);

            $controllerMap = [
                'ProductController' => 'Products',
                'ProductCategoryController' => 'Product Categories',
                'ServiceController' => 'Services',
                'AvailabilityController' => 'Provider Availability',
                'JobController' => 'Jobs',
                'JobApplicationController' => 'Jobs',
                'EventController' => 'Events',
                'TicketController' => 'Events',
                'BookingController' => 'Bookings',
                'ConnectionController' => 'Connections',
                'ConversationController' => 'Messages',
                'PostController' => 'Posts',
                'StoryController' => 'Stories',
                'LivestreamController' => 'Livestreams',
                'LivestreamInteractionController' => 'Livestreams',
                'CartController' => 'Shopping Cart',
                'OrderController' => 'Orders & Payments',
                'PaymentController' => 'Orders & Payments',
                'ShipmentController' => 'ShipEngine Shipping',
                'ReviewController' => 'Reviews',
                'WalletController' => 'Wallet',
                'EscrowController' => 'Escrow & Disputes',
                'BuyerBankAccountController' => 'Bank Accounts',
                'SavedItemController' => 'Saved Items',
                'NotificationController' => 'Notifications',
                'NotificationPreferenceController' => 'Notifications',
                'DeviceTokenController' => 'Device Tokens & Push',
                'PushSubscriptionController' => 'Device Tokens & Push',
                'SettingsController' => 'Settings & Contact',
                'ProfileController' => 'My Profile',
                'WorkHistoryController' => 'My Profile',
                'QualificationController' => 'My Profile',
                'AuthController' => 'Authentication',
                'CountryController' => 'Public',
                'ServiceCategoryController' => 'Public',
                'ProductCategoryController' => 'Product Categories',
                'FaqController' => 'Public',
                'AppSettingsController' => 'Public',
                'HomepageController' => 'Discover',
                'SearchController' => 'Discover',
                'UserController' => 'Discover',
                'ContactController' => 'Settings & Contact',
                'ShopifyFlutterController' => 'Shopify Integration',
                'ShopifyWebhookController' => 'Shopify Integration',
                'AliExpressConnectController' => 'AliExpress Integration',
                'AliExpressImportController' => 'AliExpress Integration',
                'AliExpressProductController' => 'AliExpress Integration',
                'AliExpressOrderController' => 'AliExpress Integration',
                'AliExpressInventoryController' => 'AliExpress Integration',
                'CsvImportController' => 'CSV Import',
                'SubscriptionController' => 'My Profile',
                'VerificationController' => 'My Profile',
                'TermsGuidelinesController' => 'My Profile',
                'OnboardingController' => 'My Profile',
            ];

            if (isset($controllerMap[$controllerClass])) {
                return $controllerMap[$controllerClass];
            }
        }

        return 'Other';
    }

    protected function generateOperationId(string $method, string $path): string
    {
        $segments = array_filter(explode('/', trim($path, '/')));
        $parts = array_map(function ($segment) {
            // Remove parameter placeholders
            $segment = preg_replace('/\{.*?\}/', 'ById', $segment);

            return Str::camel($segment);
        }, $segments);

        return $method.implode('', array_map('ucfirst', $parts));
    }

    protected function generateSummary(string $method, string $path): string
    {
        $segments = array_filter(explode('/', trim($path, '/')));
        $lastSegment = end($segments);

        $methodNames = [
            'get' => 'List',
            'post' => 'Create',
            'put' => 'Update',
            'patch' => 'Update',
            'delete' => 'Delete',
        ];

        // Handle parameterized paths
        if (preg_match('/\{.*?\}/', $lastSegment ?? '')) {
            $resource = Str::singular(Str::headline(preg_replace('/\{.*?\}/', '', $lastSegment ?? '')));

            return trim(($methodNames[$method] ?? ucfirst($method)).' '.$resource);
        }

        $resource = Str::headline(str_replace('-', ' ', $lastSegment ?? ''));

        if (str_ends_with($path, '/mine')) {
            return "My {$resource}";
        }

        if (str_ends_with($path, '/count')) {
            return "{$resource} Count";
        }

        return trim(($methodNames[$method] ?? ucfirst($method)).' '.$resource);
    }

    protected function buildParameters(array $route): array
    {
        $parameters = [];

        foreach ($route['parameters'] as $param) {
            $parameters[] = [
                'name' => $param,
                'in' => 'path',
                'required' => true,
                'schema' => [
                    'type' => 'integer',
                ],
            ];
        }

        // Add common query parameters for list endpoints
        if (str_ends_with($route['path'], '/') || ! preg_match('/\{.*?\}/', $route['path'])) {
            $parameters[] = [
                'name' => 'page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'default' => 1],
            ];
            $parameters[] = [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'default' => 20],
            ];
        }

        return $parameters;
    }
}
