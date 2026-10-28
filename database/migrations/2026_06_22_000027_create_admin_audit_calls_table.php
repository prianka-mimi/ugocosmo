<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->foreignId('entity_id')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caller_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('call_type');
            $table->string('status');
            $table->timestamps();
        });

        // Schema::create('call_participants', function (Blueprint $table) {
        //     $table->id();
        //     $table->foreignId('call_id')->constrained()->cascadeOnDelete();
        //     $table->foreignId('participant_id')->constrained('profiles')->cascadeOnDelete();
        //     $table->timestamp('joined_at')->nullable();
        //     $table->timestamp('left_at')->nullable();
        //     $table->string('status');
        //     $table->timestamps();
        // });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('calls');
        // Schema::dropIfExists('call_participants');
    }
};
