<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shipment carrier tracking event history.
 *
 * Populated by the ShipEngine tracking webhook (primary) and the manual
 * tracking refresh (fallback). Deduplicated by (shipment_id, external_event_id)
 * when the carrier supplies an event identifier, or by the full event tuple
 * (occurred_at + description + location) when it does not — webhook retries
 * and poll/webhook overlap can never create duplicate rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('external_event_id')->nullable();
            $table->string('status')->nullable()->index();          // normalized local status (in_transit, delivered, ...)
            $table->string('status_code')->nullable();              // raw carrier/ShipEngine code (IT, DE, ...)
            $table->text('description')->nullable();
            $table->string('city_locality')->nullable();
            $table->string('state_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamp('event_time')->nullable()->index();
            $table->json('raw_payload')->nullable();                // raw event for audit — never returned to clients wholesale
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Webhook idempotency: a given external event is stored once
            // per shipment (NULLs allowed multiple times in most engines).
            $table->unique(['shipment_id', 'external_event_id'], 'shipment_events_unique_external');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_events');
    }
};
