<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('communication_conversations')->cascadeOnDelete();
            $table->foreignId('initiator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('call_type', ['audio', 'video']);
            $table->string('engine', 32)->nullable();
            $table->string('room_name')->nullable();
            $table->enum('status', ['missed', 'completed', 'ongoing']);
            $table->integer('duration_seconds')->default(0);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();

            $table->index(['conversation_id', 'started_at']);
            $table->index(['initiator_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
