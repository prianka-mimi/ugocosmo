<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment records for the ShipEngine / ShipStation shipping integration.
 *
 * One shipment per physical fulfillment of an order. External references
 * (ShipEngine shipment/label ids), carrier/service, tracking, label
 * download, and shipping cost are persisted here — the orders table keeps
 * its legacy `tracking_number` for the manual (non-ShipEngine) flow.
 *
 * Sensitive credentials are NEVER stored here (no API keys, no carrier
 * account passwords).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('external_shipment_id')->nullable()->index();
            $table->string('external_label_id')->nullable();
            $table->string('carrier_id')->nullable();
            $table->string('carrier_code')->nullable();
            $table->string('service_code')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('label_status')->nullable();
            $table->string('tracking_number')->nullable()->index();
            $table->string('tracking_status')->nullable();
            $table->decimal('shipping_cost', 12, 2)->nullable();
            $table->string('currency')->nullable();
            $table->date('ship_date')->nullable();
            $table->string('label_format')->nullable();
            $table->text('label_download_url')->nullable();
            $table->boolean('voided')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_tracking_synced_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
