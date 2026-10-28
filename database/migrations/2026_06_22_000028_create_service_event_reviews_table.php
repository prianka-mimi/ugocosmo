<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('profiles')->cascadeOnDelete();
            $table->integer('rating');
            $table->text('review_text')->nullable();
            $table->timestamps();
        });

        Schema::create('event_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('profiles')->cascadeOnDelete();
            $table->integer('rating');
            $table->text('review_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reviews');
        Schema::dropIfExists('event_reviews');
    }
};
