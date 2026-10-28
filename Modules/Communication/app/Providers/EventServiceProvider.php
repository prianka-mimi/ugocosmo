<?php

namespace Modules\Communication\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Communication\Events\CallInitiated;
use Modules\Communication\Events\CallLifecycleUpdated;
use Modules\Communication\Listeners\CallInitiatedListener;
use Modules\Communication\Listeners\CallLifecycleUpdatedListener;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        CallInitiated::class => [
            CallInitiatedListener::class,
        ],
        CallLifecycleUpdated::class => [
            CallLifecycleUpdatedListener::class,
        ],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
