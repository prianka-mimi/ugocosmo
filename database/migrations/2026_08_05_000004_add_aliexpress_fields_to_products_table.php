<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // AliExpress source mapping (mirrors the existing shopify_* columns).
            $table->string('aliexpress_product_id')->nullable()->after('shopify_source_data');
            $table->foreignId('aliexpress_account_id')
                ->nullable()
                ->after('aliexpress_product_id')
                ->constrained('aliexpress_accounts')
                ->nullOnDelete();
            $table->string('aliexpress_product_url')->nullable()->after('aliexpress_account_id');
            $table->timestamp('aliexpress_updated_at')->nullable()->after('aliexpress_product_url');
            $table->json('aliexpress_source_data')->nullable()->after('aliexpress_updated_at');

            // Inventory: reserved = stock set aside for confirmed orders.
            // available = stock_quantity - reserved_quantity.
            // Imported AliExpress listings ALWAYS start at stock_quantity = 0.
            $table->unsignedInteger('reserved_quantity')->default(0)->after('stock_quantity');

            $table->index('aliexpress_product_id', 'idx_products_aliexpress_product');
            $table->index(
                ['seller_id', 'aliexpress_account_id'],
                'idx_products_aliexpress_seller_account'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_aliexpress_product');
            $table->dropIndex('idx_products_aliexpress_seller_account');

            $table->dropConstrainedForeignId('aliexpress_account_id');
            $table->dropColumn([
                'aliexpress_product_id',
                'aliexpress_product_url',
                'aliexpress_updated_at',
                'aliexpress_source_data',
                'reserved_quantity',
            ]);
        });
    }
};
