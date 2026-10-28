<?php

declare(strict_types=1);

namespace Modules\Communication\Providers;

use Modules\Communication\Repositories\Contracts\MessageRepositoryInterface;
use Modules\Communication\Repositories\MariaDbMessageRepository;
use Modules\Communication\Repositories\MongoDbMessageRepository;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CommunicationServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Communication';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'communication';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        $this->registerCoreFiles();

        parent::register();

        $this->app->bind(MessageRepositoryInterface::class, function ($app) {
            $driver = (string) config('communication.message_db_driver', 'mariadb');

            if ($driver === 'mongodb') {
                return $app->make(MongoDbMessageRepository::class);
            }

            return $app->make(MariaDbMessageRepository::class);
        });
    }

    public function boot(): void
    {
        $this->registerCoreFiles();

        $this->syncBroadcastConnection();

        parent::boot();
    }

    private function syncBroadcastConnection(): void
    {
        $driver = (string) env('COMMUNICATION_BROADCAST_DRIVER', '');

        if ($driver === '') {
            $driver = (string) config('communication.broadcast_driver', config('broadcasting.default', 'null'));
        }

        if (in_array($driver, ['reverb', 'pusher'], true)) {
            config(['broadcasting.default' => $driver]);
        }
    }

    private function registerCoreFiles(): void
    {
        $files = [
            module_path($this->name, 'Traits/ApiResponseTrait.php'),
            module_path($this->name, 'Traits/FileManagementTrait.php'),
            module_path($this->name, 'Traits/UniqueIdentifierTrait.php'),
            module_path($this->name, 'Helpers/TimeHelper.php'),
        ];

        foreach ($files as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
}
