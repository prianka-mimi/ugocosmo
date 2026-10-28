<?php

declare(strict_types=1);

namespace Modules\Communication\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Communication';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void
    {
        $webRoutesPath = module_path($this->name, '/routes/web.php');

        if (is_file($webRoutesPath)) {
            Route::middleware('web')->group($webRoutesPath);
        }
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        $apiRoutesPath = module_path($this->name, '/routes/api.php');

        if (is_file($apiRoutesPath)) {
            Route::middleware('api')->group($apiRoutesPath);
        }
    }
}
