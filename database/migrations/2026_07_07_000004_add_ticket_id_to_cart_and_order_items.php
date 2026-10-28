<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('service_id')->constrained('event_tickets')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('service_id')->constrained('event_tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });
    }
};
