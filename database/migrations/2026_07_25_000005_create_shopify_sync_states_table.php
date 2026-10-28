<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_store_id')
                ->constrained('shopify_stores')
                ->cascadeOnDelete();
            $table->string('resource_type');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_shopify_id')->nullable();
            $table->integer('total_count')->default(0);
            $table->timestamps();

            $table->index('resource_type', 'idx_shopify_sync_resource');
            $table->unique(
                ['shopify_store_id', 'resource_type'],
                'uq_shopify_sync_store_resource'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_sync_states');
    }
};
