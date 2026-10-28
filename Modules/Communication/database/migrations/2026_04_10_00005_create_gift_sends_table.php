<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('gift_id')->constrained('gifts')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('coin_cost')->comment('Total coins spent (unit cost × quantity)');
            $table->timestamps();

            $table->index('sender_id');
            $table->index('receiver_id');
            $table->index(['receiver_id', 'gift_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_sends');
    }
};
