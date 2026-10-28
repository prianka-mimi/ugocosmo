<?php

namespace Database\Seeders;

use App\Manager\RolePermissionManager;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds for roles and permissions.
     *
     * Delegates to RolePermissionManager::seedDefaults() which is the
     * single source of truth for permission definitions and role setup.
     */
    public function run(): void
    {
        $manager = app(RolePermissionManager::class);
        $manager->seedDefaults();

        $this->command->info('Permissions seeded and roles synced successfully.');
    }
}
