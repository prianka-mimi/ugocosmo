<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('section_name');
            $table->decimal('price', 10, 2)->nullable()->comment('Per-section ticket price; overrides global event price');
            $table->timestamps();
        });

        Schema::create('event_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('event_sections')->cascadeOnDelete();
            $table->string('seat_number');
            $table->decimal('price', 10, 2)->nullable()->comment('Individual seat price override');
            $table->enum('status', ['available', 'reserved', 'booked', 'blocked'])->default('available');
            $table->timestamps();

            $table->unique(['section_id', 'seat_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_seats');
        Schema::dropIfExists('event_sections');
    }
};
