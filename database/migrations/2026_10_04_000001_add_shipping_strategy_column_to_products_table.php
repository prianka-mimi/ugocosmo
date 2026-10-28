<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'shipping_strategy')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('shipping_strategy', 50)->default('manual')->after('shipping_info');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'shipping_strategy')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('shipping_strategy');
            });
        }
    }
};
