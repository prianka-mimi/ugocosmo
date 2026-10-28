<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('job_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('job_listings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('livestream_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('livestream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('product_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('service_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('job_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('job_listings')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('event_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_likes');
        Schema::dropIfExists('job_likes');
        Schema::dropIfExists('livestream_likes');
        Schema::dropIfExists('product_saves');
        Schema::dropIfExists('service_saves');
        Schema::dropIfExists('job_saves');
        Schema::dropIfExists('event_saves');
    }
};
