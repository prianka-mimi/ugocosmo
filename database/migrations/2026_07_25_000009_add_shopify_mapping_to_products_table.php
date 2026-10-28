<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('shopify_variant_id')->nullable();
            $table->string('shopify_inventory_item_id')->nullable();
            $table->foreignId('shopify_store_id')
                ->nullable()
                ->constrained('shopify_stores')
                ->nullOnDelete();
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('source')->default('local');

            $table->index('shopify_variant_id', 'idx_products_shopify_variant');
            $table->index('shopify_inventory_item_id', 'idx_products_shopify_inventory');
            $table->index('sync_status', 'idx_products_sync_status');
            $table->index('source', 'idx_products_source');
        });

        DB::table('products')
            ->whereNotNull('shopify_product_id')
            ->update(['source' => 'shopify', 'sync_status' => 'synced']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_shopify_variant');
            $table->dropIndex('idx_products_shopify_inventory');
            $table->dropIndex('idx_products_sync_status');
            $table->dropIndex('idx_products_source');
            $table->dropColumn([
                'shopify_variant_id', 'shopify_inventory_item_id',
                'shopify_store_id', 'sync_status', 'last_synced_at', 'source',
            ]);
        });
    }
};
