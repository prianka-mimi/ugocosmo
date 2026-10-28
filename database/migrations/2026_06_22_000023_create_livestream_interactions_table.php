<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livestream_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('livestream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
        });

        Schema::create('livestream_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('livestream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('gift_type');
            $table->decimal('amount', 10, 2)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('livestream_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('livestream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
        });

        Schema::create('livestream_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('livestream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livestream_comments');
        Schema::dropIfExists('livestream_gifts');
        Schema::dropIfExists('livestream_join_requests');
        Schema::dropIfExists('livestream_invites');
    }
};
