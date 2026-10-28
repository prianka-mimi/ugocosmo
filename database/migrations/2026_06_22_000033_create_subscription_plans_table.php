<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
