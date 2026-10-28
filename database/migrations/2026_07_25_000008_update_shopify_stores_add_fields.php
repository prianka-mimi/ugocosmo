<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_stores', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('profile_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('shop_name')->nullable();
            $table->string('status')->default('active');
            $table->string('currency')->nullable();
            $table->string('country')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->softDeletes();
        });

        Schema::table('shopify_stores', function (Blueprint $table) {
            $table->index('shop_name', 'idx_shopify_stores_name');
            $table->index('status', 'idx_shopify_stores_status');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_stores', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex('idx_shopify_stores_name');
            $table->dropIndex('idx_shopify_stores_status');
            $table->dropColumn([
                'user_id', 'shop_name', 'status', 'currency',
                'country', 'timezone', 'last_sync_at',
                'token_expires_at', 'deleted_at',
            ]);
        });
    }
};
