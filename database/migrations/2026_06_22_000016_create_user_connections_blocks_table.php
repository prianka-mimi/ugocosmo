<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('connection_type')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('profiles')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_connections');
        Schema::dropIfExists('user_blocks');
    }
};
