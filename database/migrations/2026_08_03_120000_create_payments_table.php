<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('payment_intent_id')->nullable()->unique();
            $table->string('stripe_transaction_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('USD');
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
