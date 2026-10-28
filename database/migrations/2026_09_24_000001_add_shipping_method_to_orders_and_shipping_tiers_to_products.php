<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'shipping_method')) {
                $table->string('shipping_method')->nullable()->after('shipping_address');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'shipping_tiers')) {
                $table->json('shipping_tiers')->nullable()->after('shipping_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'shipping_method')) {
                $table->dropColumn('shipping_method');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'shipping_tiers')) {
                $table->dropColumn('shipping_tiers');
            }
        });
    }
};
