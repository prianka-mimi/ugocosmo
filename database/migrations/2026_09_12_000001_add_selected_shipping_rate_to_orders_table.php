<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selected shipping rate on an order (checkout shipping flow).
 *
 * The buyer chooses a rate from POST /shipping/rates; the backend re-verified
 * the chosen rate against freshly quoted ShipEngine rates before persisting it
 * (the frontend-provided price is never trusted), then the payment intent is
 * updated so the final payable amount includes the real shipping cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('selected_rate_id')->nullable()->index();
            $table->string('selected_carrier_id')->nullable();
            $table->string('selected_carrier_code')->nullable();
            $table->string('selected_service_code')->nullable();
            $table->string('selected_service_name')->nullable();
            $table->decimal('shipping_amount', 10, 2)->nullable();
            $table->string('shipping_currency', 3)->nullable();
            // Full snapshot of the verified rate for audit/debugging.
            $table->json('selected_rate_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'selected_rate_id',
                'selected_carrier_id',
                'selected_carrier_code',
                'selected_service_code',
                'selected_service_name',
                'shipping_amount',
                'shipping_currency',
                'selected_rate_snapshot',
            ]);
        });
    }
};
