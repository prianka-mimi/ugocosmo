<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('profiles')
                ->cascadeOnDelete();
            $table->string('store_domain');
            $table->text('access_token');
            $table->string('scopes')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->index('store_domain', 'idx_shopify_stores_domain');
            $table->unique(
                ['profile_id', 'store_domain'],
                'uq_shopify_stores_profile_domain'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_stores');
    }
};
