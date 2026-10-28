<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aliexpress_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('profiles')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // AliExpress member/seller identifier returned by OAuth.
            // Nullable until the first successful callback completes.
            $table->string('aliexpress_member_id')->nullable();
            $table->string('account_name')->nullable();

            // Tokens are ALWAYS stored encrypted via Crypt::encryptString()
            // (see AliExpressAccountRepository). Never plaintext.
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_token_valid_until')->nullable();

            $table->string('scopes')->nullable();
            $table->string('region')->default('sg'); // sg | global
            $table->string('status')->default('active'); // active | disabled
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One AliExpress account per seller (member). Multiple NULL
            // member IDs are allowed by MySQL/Postgres, so a half-connected
            // account never blocks a retry.
            $table->unique(
                ['profile_id', 'aliexpress_member_id'],
                'uq_aliexpress_accounts_profile_member'
            );
            $table->index('user_id', 'idx_aliexpress_accounts_user');
            $table->index('status', 'idx_aliexpress_accounts_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aliexpress_accounts');
    }
};
