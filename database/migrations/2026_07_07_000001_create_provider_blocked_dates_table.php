<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_blocked_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('profiles')->cascadeOnDelete();
            $table->date('blocked_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_blocked_dates');
    }
};
