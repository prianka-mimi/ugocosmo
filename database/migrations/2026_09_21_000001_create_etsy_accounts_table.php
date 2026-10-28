<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etsy_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('profiles')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // The Etsy OAuth access_token embeds the Etsy user_id as its
            // numeric prefix (e.g. "12345678.<token>"). We store the parsed
            // prefix separately so reconnects can match the SAME Etsy user
            // across token rotations — the equivalent of AliExpress member_id.
            $table->string('etsy_user_id')->nullable();
            $table->string('shop_id')->nullable();
            $table->string('shop_name')->nullable();
            $table->string('account_name')->nullable();

            // Tokens are ALWAYS stored encrypted via Crypt::encryptString()
            // (see EtsyAccountRepository). Never plaintext.
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            // Etsy refresh tokens live 90 days and are NOT rotated on refresh.
            $table->timestamp('refresh_token_valid_until')->nullable();

            $table->string('scopes')->nullable();
            $table->string('status')->default('active'); // active | disabled
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One Etsy account per seller (Etsy user). Multiple NULL user IDs
            // are allowed by MySQL/Postgres, so a half-connected account never
            // blocks a retry.
            $table->unique(
                ['profile_id', 'etsy_user_id'],
                'uq_etsy_accounts_profile_user'
            );
            $table->index('user_id', 'idx_etsy_accounts_user');
            $table->index('status', 'idx_etsy_accounts_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etsy_accounts');
    }
};
