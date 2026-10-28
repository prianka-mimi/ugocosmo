<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_merchant_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_id');
            $table->string('offer_id');
            $table->string('content_language', 10)->default('en');
            $table->string('feed_label', 10)->default('US');
            $table->string('resource_name')->nullable(); // accounts/{id}/productInputs/{name}
            $table->string('product_resource_name')->nullable(); // accounts/{id}/products/{name}
            $table->string('sync_status')->default('pending'); // pending|synced|error|deleted
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_status_check_at')->nullable();
            $table->json('google_status')->nullable(); // productStatus from Google
            $table->timestamps();

            $table->unique(['product_id', 'content_language', 'feed_label'], 'gmp_prod_lang_feed_uq');
            $table->index('sync_status');
            $table->index('offer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_merchant_products');
    }
};
