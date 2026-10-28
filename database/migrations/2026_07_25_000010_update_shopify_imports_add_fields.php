<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_imports', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->json('selected_product_ids')->nullable();
        });

        Schema::table('shopify_import_logs', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shopify_imports', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'selected_product_ids']);
        });

        Schema::table('shopify_import_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['product_id']);
            $table->dropColumn(['user_id', 'product_id']);
        });
    }
};
