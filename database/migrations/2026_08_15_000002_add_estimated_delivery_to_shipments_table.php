<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Carrier-provided estimated delivery date (from tracking sync).
            $table->date('estimated_delivery_date')->nullable()->after('ship_date');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('estimated_delivery_date');
        });
    }
};
