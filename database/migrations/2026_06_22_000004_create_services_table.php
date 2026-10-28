<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('price_type')->nullable();
            $table->string('service_type')->nullable();
            $table->string('category')->nullable();
            $table->string('image_url')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
