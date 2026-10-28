<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_log_id')->constrained('call_logs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['invited', 'joined', 'left', 'kicked', 'ended'])->default('invited');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->foreignId('kicked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['call_log_id', 'user_id']);
            $table->index(['call_log_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_participants');
    }
};
