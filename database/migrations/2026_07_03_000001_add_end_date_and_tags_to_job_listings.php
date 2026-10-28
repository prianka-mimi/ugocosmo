<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('is_active');
        });

        Schema::create('job_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30);
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('job_listing_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_listing_id')->constrained('job_listings')->cascadeOnDelete();
            $table->foreignId('job_tag_id')->constrained('job_tags')->cascadeOnDelete();

            $table->unique(['job_listing_id', 'job_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_listing_tag');
        Schema::dropIfExists('job_tags');

        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn('end_date');
        });
    }
};
