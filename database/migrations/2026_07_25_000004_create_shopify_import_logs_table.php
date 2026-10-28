<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_import_id')
                ->constrained('shopify_imports')
                ->cascadeOnDelete();
            $table->foreignId('shopify_store_id')
                ->constrained('shopify_stores')
                ->cascadeOnDelete();
            $table->string('shopify_product_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('action');
            $table->string('reason')->nullable();
            $table->text('error_message')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index('shopify_product_id', 'idx_shopify_logs_product');
            $table->index('action', 'idx_shopify_logs_action');
            $table->index(
                ['shopify_import_id', 'action'],
                'idx_shopify_logs_import_action'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_import_logs');
    }
};
