<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignId('provider_id')->constrained('profiles')->cascadeOnDelete();
            $table->date('booking_date');
            $table->time('booking_time');
            $table->string('status')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('buyer_fee', 10, 2)->nullable();
            $table->decimal('seller_fee', 10, 2)->nullable();
            $table->decimal('platform_fee_total', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
