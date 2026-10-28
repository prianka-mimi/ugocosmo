<?php

namespace App\Providers;

use App\Models\Event;
use App\Models\JobListing;
use App\Models\Product;
use App\Observers\EventObserver;
use App\Observers\JobListingObserver;
use App\Observers\ProductObserver;
use App\Support\ShipstationWebhookVerifier;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            ShipstationWebhookVerifier::class,
            fn () => ShipstationWebhookVerifier::fromConfig(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Product::observe(ProductObserver::class);
        JobListing::observe(JobListingObserver::class);
        Event::observe(EventObserver::class);

        // ─── Blade Directives ──────────────────────────────────────────
        Blade::directive('canPermission', function (string $expression) {
            $permission = trim($expression, "'");

            return "<?php if(auth()->check() && (auth()->user()->role === 'admin' || (auth()->user()->can('{$permission}')))): ?>";
        });

        Blade::directive('endcanPermission', function () {
            return '<?php endif; ?>';
        });

        // ─── Scramble Route Selection ──────────────────────────────────
        Scramble::routes(function (Route $route) {
            return Str::startsWith($route->uri(), 'api/');
        });

        // ─── Global Universal Tree & Name Resolver for Apidog ─────────
        Scramble::extendOpenApi(function (OpenApi $openApi) {
            $apiPhpPath = base_path('routes/api.php');
            $routeFolderMap = [];

            // 1. Read api.php and map route paths to comments hierarchy
            if (file_exists($apiPhpPath)) {
                $lines = file($apiPhpPath, FILE_IGNORE_NEW_LINES);

                $currentRoot = 'UNAUTHENTICATED & PUBLIC ENDPOINTS';
                $currentMain = 'Public';
                $currentSub = null;

                $authIndex = 1;
                $numberedMainFolders = [];
                $prefixStack = [];

                foreach ($lines as $line) {
                    $trimmed = trim($line);

                    // Level 1 Root Header: // ════ UNAUTHENTICATED... / AUTHENTICATED...
                    if (preg_match('/\/\/\s*[═=]{3,}\s*([^═=]+)/u', $trimmed, $m)) {
                        $currentRoot = trim($m[1]);
                        $currentMain = Str::contains($currentRoot, 'UNAUTHENTICATED') ? 'Public' : 'General';
                        $currentSub = null;

                        continue;
                    }

                    // Level 2 Main Module: // ─── Module Name ───
                    if (preg_match('/\/\/\s*[─-]{3,}\s*([^─-]+)/u', $trimmed, $m)) {
                        $mainRaw = trim(preg_replace('/[^\p{L}\p{N}\s\(\)\&\-\,\.\_]/u', '', $m[1]));
                        $mainClean = trim(preg_replace('/\s+/', ' ', $mainRaw));

                        if (! empty($mainClean)) {
                            $currentMain = $mainClean;
                            $currentSub = null;

                            // Serial numbering only for Authenticated main modules
                            if (Str::contains($currentRoot, 'AUTHENTICATED') && ! Str::contains($currentRoot, 'UNAUTHENTICATED')) {
                                if (! isset($numberedMainFolders[$currentMain])) {
                                    $numberedMainFolders[$currentMain] = sprintf('%02d. %s', $authIndex++, $currentMain);
                                }
                            }
                        }

                        continue;
                    }

                    // Level 3 Sub-folder: // ── Subfolder Name ──
                    if (preg_match('/\/\/\s*[─-]{2}\s*([^─-]+)/u', $trimmed, $m)) {
                        $subRaw = trim(preg_replace('/[^\p{L}\p{N}\s\(\)\&\-\,\.\_]/u', '', $m[1]));
                        $subClean = trim(preg_replace('/\s+/', ' ', $subRaw));

                        if (! empty($subClean) && ! Str::is($subClean, $currentMain)) {
                            $currentSub = $subClean;
                        }

                        continue;
                    }

                    // Track Route Groups
                    if (preg_match('/Route::prefix\([\'"]([^\'"]+)[\'"]\)/', $trimmed, $m)) {
                        $prefixStack[] = trim($m[1], '/');
                    }
                    if (Str::contains($trimmed, '});') && ! empty($prefixStack)) {
                        array_pop($prefixStack);
                    }

                    // Match Route Registration
                    if (preg_match('/Route::(get|post|put|patch|delete|match)\s*\(\s*(?:\[[^\]]+\]\s*,\s*)?[\'"]([^\'"]*)[\'"]/', $trimmed, $m)) {
                        $routeUriPart = trim($m[2], '/');
                        $fullUriSegments = array_filter(array_merge($prefixStack, explode('/', $routeUriPart)));
                        $normalizedPath = implode('/', $fullUriSegments);

                        $activeMain = $numberedMainFolders[$currentMain] ?? $currentMain;
                        $routeFolderMap[$normalizedPath] = [
                            'root' => $currentRoot,
                            'main' => $activeMain,
                            'sub' => $currentSub,
                        ];
                    }
                }
            }

            // 2. Index Laravel routes by HTTP method and URI for metadata lookup
            $allRoutes = app('router')->getRoutes();
            $routesByMethodUri = [];
            foreach ($allRoutes as $r) {
                $rUri = trim(Str::after(ltrim($r->uri(), '/'), 'api/'), '/');
                foreach ($r->methods() as $m) {
                    $routesByMethodUri[strtoupper($m).' '.$rUri] = $r;
                }
            }

            // 3. Assign Hierarchical Tags and Clean Action Names
            foreach ($openApi->paths as $pathItem) {
                foreach ($pathItem->operations as $operation) {
                    $rawUri = $operation->getAttribute('routeInfo')?->route?->uri() ?? ($operation->path ?: $pathItem->path);
                    if (! $rawUri) {
                        continue;
                    }

                    $cleanUri = Str::after(ltrim($rawUri, '/'), 'api/');
                    $cleanUriNormalized = trim($cleanUri, '/');

                    // Resolve Folder Hierarchy
                    $matched = $routeFolderMap[$cleanUriNormalized] ?? null;

                    if (! $matched) {
                        $firstSeg = explode('/', $cleanUriNormalized)[0] ?? '';
                        foreach ($routeFolderMap as $mappedPath => $meta) {
                            if (! empty($firstSeg) && Str::startsWith($mappedPath, $firstSeg)) {
                                $matched = $meta;
                                break;
                            }
                        }
                    }

                    if ($matched) {
                        $root = $matched['root'];
                        $main = $matched['main'];
                        $sub = $matched['sub'];

                        if (! empty($sub)) {
                            $folderPath = "{$root}/{$main}/{$sub}";
                        } else {
                            $folderPath = "{$root}/{$main}";
                        }

                        $operation->tags = [$folderPath];
                        $operation->setAttribute('x-apidog-folder', $folderPath);
                    }

                    // 4. Meaningful Human-Readable Endpoint Summary (Method Name ছাড়া)
                    $routeKey = strtoupper($operation->method).' '.$cleanUriNormalized;
                    $matchedRoute = $operation->getAttribute('routeInfo')?->route ?? ($routesByMethodUri[$routeKey] ?? null);
                    $action = $matchedRoute ? ($matchedRoute->getAction()['uses'] ?? '') : '';
                    $methodName = is_string($action) && str_contains($action, '@') ? Str::after($action, '@') : '';

                    $nameMap = [
                        'index' => 'List / Browse',
                        'store' => 'Create / Store',
                        'show' => 'Get Details',
                        'update' => 'Update',
                        'destroy' => 'Delete',
                        'like' => 'Execute Like',
                        'comment' => 'Add Comment',
                        'reply' => 'Reply to Comment',
                        'react' => 'React',
                        'save' => 'Save / Bookmark',
                        'share' => 'Share',
                        'reshare' => 'Reshare',
                        'report' => 'Report',
                        'importedProducts' => 'Imported Products List',
                        'status' => 'Get Status',
                        'connect' => 'Connect Store / Account',
                    ];

                    $readable = $nameMap[$methodName] ?? Str::headline($methodName);
                    $subject = Str::headline(last(explode('/', preg_replace('/\{[^}]+\}/', '', $cleanUriNormalized))));

                    if (! empty($readable) && ! empty($subject) && ! Str::contains(strtolower($readable), strtolower($subject))) {
                        $operation->summary = "{$readable} ({$subject})";
                    } elseif (! empty($readable)) {
                        $operation->summary = $readable;
                    }
                }
            }
        });
    }
}
