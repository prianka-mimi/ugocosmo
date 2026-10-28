<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_livestream_polls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_log_id')->constrained('call_logs')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('question');
            $table->string('status')->default('active'); // active, ended
            $table->unsignedBigInteger('total_votes')->default(0);
            $table->timestamps();

            $table->index('call_log_id');
            $table->index(['call_log_id', 'status']);
        });

        Schema::create('communication_livestream_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('communication_livestream_polls')->cascadeOnDelete();
            $table->string('option_text');
            $table->unsignedBigInteger('votes_count')->default(0);
            $table->timestamps();

            $table->index('poll_id');
        });

        Schema::create('communication_livestream_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('communication_livestream_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('communication_livestream_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['poll_id', 'user_id']);
            $table->index('option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_livestream_poll_votes');
        Schema::dropIfExists('communication_livestream_poll_options');
        Schema::dropIfExists('communication_livestream_polls');
    }
};
