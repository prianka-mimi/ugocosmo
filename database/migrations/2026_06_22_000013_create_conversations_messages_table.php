<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_1')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('participant_2')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message_type')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('profiles')->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('messages');
    }
};
