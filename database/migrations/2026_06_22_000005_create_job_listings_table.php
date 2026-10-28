<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poster_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('company')->nullable();
            $table->string('location')->nullable();
            $table->decimal('salary_min', 10, 2)->nullable();
            $table->decimal('salary_max', 10, 2)->nullable();
            $table->string('job_type')->nullable();
            $table->boolean('is_remote')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_listings');
    }
};
