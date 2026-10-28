<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('currency', 10)->nullable()->after('compare_at_price');
            $table->string('condition')->nullable()->index()->after('product_type'); // new, used, refurbished
            $table->string('brand')->nullable()->index()->after('condition');
            $table->string('sku')->nullable()->index()->after('brand');
            $table->json('tags')->nullable()->after('images');
            $table->decimal('weight', 8, 2)->nullable()->after('stock_quantity');
            $table->string('shipping_info')->nullable()->after('weight');
            $table->string('location')->nullable()->after('shipping_info');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['currency', 'condition', 'brand', 'sku', 'tags', 'weight', 'shipping_info', 'location']);
        });
    }
};
