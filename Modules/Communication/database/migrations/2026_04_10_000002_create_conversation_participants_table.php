<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('communication_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['admin', 'member'])->default('member');
            $table->enum('status', ['active', 'left', 'kicked'])->default('active');
            $table->boolean('notification_enabled')->default(true);
            $table->boolean('receive_audio_call_enabled')->default(true);
            $table->string('history_cleared_message_id')->nullable(); // Track the last message ID when history was cleared
            $table->string('last_msg_delivery_id')->nullable();
            $table->timestamp('last_msg_delivery_at')->nullable();
            $table->string('last_msg_read_id')->nullable();
            $table->timestamp('last_msg_read_at')->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('deleted_from_list_at')->nullable();

            $table->index(['conversation_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
