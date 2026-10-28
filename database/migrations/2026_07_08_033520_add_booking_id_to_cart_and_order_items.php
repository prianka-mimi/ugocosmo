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
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('service_id')->constrained('bookings')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('service_id')->constrained('bookings')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropColumn('booking_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropColumn('booking_id');
        });
    }
};
