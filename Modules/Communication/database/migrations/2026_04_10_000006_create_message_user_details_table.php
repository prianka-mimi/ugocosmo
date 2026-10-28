<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_user_details', function (Blueprint $table): void {
            $table->id();
            $table->string('message_id');
            $table->foreign('message_id')->references('id')->on('communication_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('reaction')->nullable();
            $table->timestamp('reaction_added_at')->nullable();

            $table->index(['message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_user_details');
    }
};
