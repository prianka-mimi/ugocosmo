<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_store_id')
                ->constrained('shopify_stores')
                ->cascadeOnDelete();
            $table->foreignId('profile_id')
                ->constrained('profiles')
                ->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->integer('total_products')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->json('errors')->nullable();
            $table->string('error_message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_shopify_imports_status');
            $table->index(
                ['shopify_store_id', 'status', 'created_at'],
                'idx_shopify_imports_store_status_created'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_imports');
    }
};
