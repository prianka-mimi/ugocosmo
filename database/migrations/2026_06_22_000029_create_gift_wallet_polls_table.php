<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->decimal('balance', 10, 2)->default(0);
            $table->decimal('total_deposited', 10, 2)->default(0);
            $table->decimal('total_withdrawn', 10, 2)->default(0);
            $table->decimal('total_gifted', 10, 2)->default(0);
            $table->decimal('total_received', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('gift_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('gift_wallets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 10, 2)->nullable();
            $table->decimal('balance_after', 10, 2)->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('status');
            $table->string('payment_method')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('seller_bank_accounts')->nullOnDelete();
            $table->foreignId('livestream_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->string('file_url');
            $table->string('file_name');
            $table->string('file_type');
            $table->timestamps();
        });

        Schema::create('discount_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('discount_type', 20)->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->string('applicable_to', 20)->nullable();
            $table->foreignId('listing_id')->nullable();
            $table->decimal('min_purchase', 10, 2)->nullable();
            $table->integer('max_uses')->nullable();
            $table->integer('uses_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_search_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('search_type');
            $table->text('search_query');
            $table->json('filters')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_recommendations_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('recommendation_type');
            $table->json('recommendations');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('rate_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('action_type');
            $table->integer('action_count')->default(0);
            $table->timestamp('window_start')->nullable();
            $table->timestamps();
        });

        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('phone');
            $table->string('code');
            $table->string('purpose');
            $table->timestamp('expires_at');
            $table->boolean('verified')->default(false);
            $table->timestamps();
        });

        Schema::create('livestream_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('livestream_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->json('options');
            $table->boolean('is_active')->default(true);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('livestream_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('livestream_polls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->integer('selected_option');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_wallets');
        Schema::dropIfExists('gift_wallet_transactions');
        Schema::dropIfExists('booking_attachments');
        Schema::dropIfExists('discount_codes');
        Schema::dropIfExists('user_search_history');
        Schema::dropIfExists('ai_recommendations_cache');
        Schema::dropIfExists('rate_limits');
        Schema::dropIfExists('verification_codes');
        Schema::dropIfExists('livestream_polls');
        Schema::dropIfExists('livestream_poll_votes');
    }
};
