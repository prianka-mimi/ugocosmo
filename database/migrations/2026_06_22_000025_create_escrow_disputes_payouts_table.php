<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrow_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_reference');
            $table->string('status');
            $table->foreignId('buyer_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignId('seller_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamp('payment_received_at')->nullable();
            $table->foreignId('payment_confirmed_by')->nullable()->constrained('profiles')->nullOnDelete();
            $table->timestamp('escrow_released_at')->nullable();
            $table->foreignId('escrow_released_by')->nullable()->constrained('profiles')->nullOnDelete();
            $table->timestamp('payout_sent_at')->nullable();
            $table->foreignId('payout_sent_by')->nullable()->constrained('profiles')->nullOnDelete();
            $table->string('payout_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('escrow_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('raised_by')->constrained('profiles')->cascadeOnDelete();
            $table->string('reason');
            $table->text('description')->nullable();
            $table->json('evidence_urls')->nullable();
            $table->string('status');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('profiles')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('seller_bank_account_id')->nullable()->constrained('seller_bank_accounts')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('status');
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('profiles')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_payments');
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('payouts');
    }
};
