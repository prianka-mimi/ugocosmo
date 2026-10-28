<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('profiles')->cascadeOnDelete();
            $table->integer('rating');
            $table->text('review_text')->nullable();
            $table->timestamps();
        });

        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });

        Schema::create('job_qualifying_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('job_listings')->cascadeOnDelete();
            $table->text('question');
            $table->boolean('is_required')->default(false);
            $table->text('expected_answer')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('job_listings')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('status')->nullable();
            $table->json('answers')->nullable();
            $table->boolean('is_qualified')->default(false);
            $table->boolean('is_shortlisted')->default(false);
            $table->text('cover_letter')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('service_packages');
        Schema::dropIfExists('job_qualifying_questions');
        Schema::dropIfExists('job_applications');
    }
};
