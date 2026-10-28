<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsored_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('listing_type');
            $table->foreignId('listing_id');
            $table->json('keywords')->nullable();
            $table->integer('priority')->default(0);
            $table->decimal('daily_budget', 10, 2)->nullable();
            $table->decimal('total_spent', 10, 2)->default(0);
            $table->integer('impressions')->default(0);
            $table->integer('clicks')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsored_listings');
    }
};
