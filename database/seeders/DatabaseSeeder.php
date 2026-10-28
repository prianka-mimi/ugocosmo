<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ─── Static/config seeders (no user dependency) ───────────────
        $this->call([
            CountrySeeder::class,
            PageSeeder::class,
            FaqSeeder::class,
            SubscriptionPlanSeeder::class,
            RolePermissionSeeder::class,
            ServiceCategorySeeder::class,
            ProductCategorySeeder::class,
            NotificationSeeder::class,
        ]);

        // ─── Admin user ───────────────────────────────────────────────
        $admin = User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'username' => 'admin',
                'password' => Hash::make('12345678'),
                'role' => 'admin',
                'status' => 'active',
            ]
        );
        $admin->syncRoles(['admin']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command->info('Admin user ensured with email: admin@admin.com | password: 12345678');

        // ─── Sample users & profiles ──────────────────────────────────
        $this->call([
            UserSeeder::class,
            ProfileSeeder::class,
        ]);

        // ─── Profile sub-modules ──────────────────────────────────────
        $this->call([
            WorkHistorySeeder::class,
            QualificationSeeder::class,
            ProviderAvailabilitySeeder::class,
            UserSubscriptionSeeder::class,
            DeviceTokenSeeder::class,
        ]);

        // ─── Marketplace modules ──────────────────────────────────────
        $this->call([
            ProductSeeder::class,
            ServiceSeeder::class,
            JobSeeder::class,
            EventSeeder::class,
        ]);

        // ─── Social modules ───────────────────────────────────────────
        $this->call([
            PostSeeder::class,
            StorySeeder::class,
            ConnectionSeeder::class,
            ConversationSeeder::class,
            LivestreamSeeder::class,
        ]);

        // ─── Transactional modules ────────────────────────────────────
        $this->call([
            WalletSeeder::class,
            OrderSeeder::class,
            ShipEngineOrderSeeder::class,
            BookingSeeder::class,
            EscrowSeeder::class,
            JobApplicationSeeder::class,
            EventTicketSeeder::class,
        ]);

        // ─── Reviews & notifications ──────────────────────────────────
        $this->call([
            ReviewSeeder::class,
            NotificationSeeder::class,
            SavedItemSeeder::class,
            CartSeeder::class,
        ]);

        // ─── Full test data for p@gmail.com (run last) ────────────────
        $this->call([
            FullTestDataSeeder::class,
        ]);
    }
}
