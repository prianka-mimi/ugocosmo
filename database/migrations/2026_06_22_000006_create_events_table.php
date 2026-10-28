<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('event_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->integer('max_attendees')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
