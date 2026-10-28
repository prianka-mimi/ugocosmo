<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->foreignId('section_id')
                ->after('customer_id')
                ->nullable()
                ->constrained('event_sections')
                ->nullOnDelete();

            $table->foreignId('seat_id')
                ->after('section_id')
                ->nullable()
                ->constrained('event_seats')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
            $table->dropConstrainedForeignId('seat_id');
        });
    }
};
