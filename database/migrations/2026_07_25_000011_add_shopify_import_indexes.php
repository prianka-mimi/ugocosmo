<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Products table: add Shopify timestamps + barcode for import ───
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'shopify_updated_at')) {
                $table->timestamp('shopify_updated_at')
                    ->nullable()
                    ->after('shopify_store_url');
            }
            if (! Schema::hasColumn('products', 'shopify_published_at')) {
                $table->timestamp('shopify_published_at')
                    ->nullable()
                    ->after('shopify_updated_at');
            }
            if (! Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode', 100)
                    ->nullable()
                    ->after('sku');
            }
        });

        // ─── Composite unique index for deduplication ──────────────────
        // Prevents duplicate imports by (source + shopify_product_id + shopify_store_id)
        Schema::table('products', function (Blueprint $table) {
            $table->unique(
                ['source', 'shopify_product_id', 'shopify_store_id'],
                'uq_products_shopify_import'
            );
        });

        // ─── Index for incremental sync by store + updated_at ──────────
        Schema::table('products', function (Blueprint $table) {
            $table->index(
                ['shopify_store_id', 'shopify_updated_at'],
                'idx_products_shopify_sync'
            );
        });

        // ─── Index for filtering by source + sync_status ───────────────
        Schema::table('products', function (Blueprint $table) {
            $table->index(
                ['source', 'sync_status'],
                'idx_products_source_status'
            );
        });

        // ─── shopify_imports: add updated_count column + index ─────────
        Schema::table('shopify_imports', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_imports', 'updated_count')) {
                $table->integer('updated_count')->default(0)->after('imported_count');
            }
            $table->index(
                ['shopify_store_id', 'created_at'],
                'idx_shopify_imports_store_created'
            );
        });

        // ─── shopify_sync_states: add index for updated_at based sync ───
        Schema::table('shopify_sync_states', function (Blueprint $table) {
            $table->timestamp('last_updated_at')
                ->nullable()
                ->after('last_synced_at');
            $table->index(
                ['shopify_store_id', 'resource_type', 'last_updated_at'],
                'idx_shopify_sync_state_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('uq_products_shopify_import');
            $table->dropIndex('idx_products_shopify_sync');
            $table->dropIndex('idx_products_source_status');
            $table->dropColumn(['shopify_updated_at', 'shopify_published_at']);
        });

        Schema::table('shopify_imports', function (Blueprint $table) {
            $table->dropIndex('idx_shopify_imports_store_created');
        });

        Schema::table('shopify_sync_states', function (Blueprint $table) {
            $table->dropIndex('idx_shopify_sync_state_lookup');
            $table->dropColumn('last_updated_at');
        });
    }
};
