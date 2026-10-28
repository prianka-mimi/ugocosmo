<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CSV products are identified by (seller_id, sku) — a seller's SKU is
     * their catalog identity. A plain unique index on (seller_id, sku) is
     * NOT safe because AliExpress/Shopify imports also write to the shared
     * `sku` column using non-globally-unique values (e.g. variant codes
     * like "BLACK"). To scope uniqueness to CSV-imported rows only we use
     * a STORED generated column that is NULL for every other source —
     * NULLs never collide in a unique index on either MySQL or SQLite.
     *
     * SKUs are lower-cased so "ABC" and "abc" are treated as the same
     * product regardless of the collation in use.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('csv_source_data')->nullable()->after('aliexpress_source_data');

            $table->string('csv_sku')
                ->nullable()
                ->storedAs("LOWER(CASE WHEN source = 'csv' THEN sku END)")
                ->after('sku');

            $table->unique(
                ['seller_id', 'csv_sku'],
                'idx_products_csv_unique_seller_sku'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('idx_products_csv_unique_seller_sku');

            if (Schema::hasColumn('products', 'csv_sku')) {
                $table->dropColumn('csv_sku');
            }
            if (Schema::hasColumn('products', 'csv_source_data')) {
                $table->dropColumn('csv_source_data');
            }
        });
    }
};
