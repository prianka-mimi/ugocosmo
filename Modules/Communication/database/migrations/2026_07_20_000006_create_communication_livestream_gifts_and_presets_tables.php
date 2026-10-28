<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_livestream_amount_presets', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 10, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'comm_stream_presets_active_sort_idx');
        });

        Schema::create('communication_livestream_gifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_log_id')->constrained('call_logs')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('gift_id')->nullable()->constrained('gifts')->nullOnDelete();
            $table->string('gift_type')->nullable(); // e.g. Coin, Heart, Star, Diamond, Crown
            $table->decimal('amount', 10, 2);
            $table->text('message')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('status')->default('pending'); // pending, completed, failed
            $table->timestamps();

            $table->index('call_log_id');
            $table->index('sender_id');
            $table->index(['call_log_id', 'created_at'], 'comm_stream_gifts_call_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_livestream_gifts');
        Schema::dropIfExists('communication_livestream_amount_presets');
    }
};
