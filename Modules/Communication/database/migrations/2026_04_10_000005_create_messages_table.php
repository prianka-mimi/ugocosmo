<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_messages', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('conversation_id')->constrained('communication_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('type')->default('text');
            $table->text('content')->nullable();
            $table->json('attachments')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('sent');
            $table->json('reactions_summary')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['sender_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_messages');
    }
};
