<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('service_service_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_tag_id')->constrained()->cascadeOnDelete();
            $table->unique(['service_id', 'service_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_service_tag');
        Schema::dropIfExists('service_tags');
    }
};
