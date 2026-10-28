<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unique(
                ['source', 'aliexpress_product_id', 'aliexpress_account_id'],
                'idx_products_aliexpress_unique_source_product_account'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('idx_products_aliexpress_unique_source_product_account');
        });
    }
};
