<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            $table->timestamps();
        });

        Schema::create('event_event_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('event_tag_id')->constrained('event_tags')->cascadeOnDelete();
            $table->unique(['event_id', 'event_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_event_tag');
        Schema::dropIfExists('event_tags');
    }
};
