<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_store_id')
                ->constrained('shopify_stores')
                ->cascadeOnDelete();
            $table->string('shopify_collection_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->string('collection_type')->default('manual');
            $table->json('rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['shopify_store_id', 'shopify_collection_id'],
                'uq_shopify_collection'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_collections');
    }
};
