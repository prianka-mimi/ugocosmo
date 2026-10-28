<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignId('seller_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('status')->nullable();
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('buyer_fee', 10, 2)->nullable();
            $table->decimal('seller_fee', 10, 2)->nullable();
            $table->decimal('platform_fee_total', 10, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->json('shipping_address')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
