<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_stores', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_stores', 'price_sync_policy')) {
                $table->string('price_sync_policy', 30)
                    ->default('shopify_always')
                    ->after('timezone');
            }
            if (! Schema::hasColumn('shopify_stores', 'image_sync_strategy')) {
                $table->string('image_sync_strategy', 30)
                    ->default('always')
                    ->after('price_sync_policy');
            }
            if (! Schema::hasColumn('shopify_stores', 'inventory_sync_enabled')) {
                $table->boolean('inventory_sync_enabled')
                    ->default(true)
                    ->after('image_sync_strategy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopify_stores', function (Blueprint $table) {
            $table->dropColumn([
                'price_sync_policy',
                'image_sync_strategy',
                'inventory_sync_enabled',
            ]);
        });
    }
};
