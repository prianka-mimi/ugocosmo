<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('profiles')->cascadeOnDelete();
            $table->text('reason');
            $table->timestamps();

            $table->index(['post_id', 'reporter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_reports');
    }
};
