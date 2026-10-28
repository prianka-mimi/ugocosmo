<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_webhook_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_store_id')
                ->nullable()
                ->constrained('shopify_stores')
                ->nullOnDelete();
            $table->string('topic');
            $table->string('shop_domain')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('received');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('topic', 'idx_shopify_webhooks_topic');
            $table->index('status', 'idx_shopify_webhooks_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_webhook_calls');
    }
};
