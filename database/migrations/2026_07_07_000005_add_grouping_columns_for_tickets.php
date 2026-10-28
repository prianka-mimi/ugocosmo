<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('service_id')->constrained('events')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('service_id')->constrained('events')->nullOnDelete();
        });

        Schema::table('event_tickets', function (Blueprint $table) {
            $table->foreignId('cart_item_id')->nullable()->after('seat_id')->constrained('cart_items')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->after('cart_item_id')->constrained('order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });

        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cart_item_id');
            $table->dropConstrainedForeignId('order_item_id');
        });
    }
};
