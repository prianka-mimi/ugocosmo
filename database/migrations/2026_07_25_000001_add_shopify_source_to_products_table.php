<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('shopify_product_id')->nullable()->index();
            $table->string('shopify_store_url')->nullable();
            $table->json('shopify_source_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['shopify_product_id', 'shopify_store_url', 'shopify_source_data']);
        });
    }
};
