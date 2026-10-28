<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etsy import columns on products (Etsy Open API v3, platform API key).
 *
 * Mirrors the AliExpress import mapping: an imported Etsy listing is a
 * regular marketplace product with `source = 'etsy'` and the external
 * identity + raw payload stored on the product itself. There is no
 * etsy_accounts table — Etsy public listing data uses one platform-level
 * API key (config services.etsy.api_key), so ownership is the importing
 * seller (products.seller_id) and the unique index enforces one listing
 * per seller (different sellers may import the same Etsy listing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('etsy_listing_id')->nullable()->after('csv_source_data');
            $table->unsignedBigInteger('etsy_shop_id')->nullable()->after('etsy_listing_id');
            $table->string('etsy_shop_name')->nullable()->after('etsy_shop_id');
            $table->string('etsy_state')->nullable()->after('etsy_shop_name');
            $table->string('etsy_product_url')->nullable()->after('etsy_state');
            $table->timestamp('etsy_updated_at')->nullable()->after('etsy_product_url');
            $table->json('etsy_source_data')->nullable()->after('etsy_updated_at');

            // Imported Etsy listings start with no local stock — inventory is
            // owned exclusively by the existing inventory workflow, exactly
            // like AliExpress/CSV imports.
            $table->index('etsy_listing_id', 'idx_products_etsy_listing');

            // Idempotency + concurrent-import race guard: one Etsy listing
            // per seller. Partial (seller_id, etsy_listing_id) NULL pairs are
            // allowed, so non-Etsy products are unaffected.
            $table->unique(
                ['seller_id', 'etsy_listing_id'],
                'uq_products_seller_etsy_listing'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('uq_products_seller_etsy_listing');
            $table->dropIndex('idx_products_etsy_listing');

            $columns = [
                'etsy_listing_id',
                'etsy_shop_id',
                'etsy_shop_name',
                'etsy_state',
                'etsy_product_url',
                'etsy_updated_at',
                'etsy_source_data',
            ];

            $existing = array_filter(
                $columns,
                fn (string $column) => Schema::hasColumn('products', $column)
            );

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
