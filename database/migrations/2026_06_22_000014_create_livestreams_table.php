<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livestreams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->boolean('is_live')->default(false);
            $table->integer('viewer_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('livestream_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('livestream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('job_listings')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livestreams');
        Schema::dropIfExists('livestream_items');
    }
};
