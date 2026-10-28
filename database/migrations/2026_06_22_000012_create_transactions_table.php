<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->string('transaction_type')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->decimal('platform_fee', 10, 2)->nullable();
            $table->decimal('seller_revenue', 10, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->string('stripe_payment_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
