<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for ShipEngine tracking webhooks.
 *
 * ShipEngine redelivers webhooks (network retries, at-least-once
 * delivery). Every processed webhook is recorded here keyed by its
 * resource token + event type; a redelivery short-circuits before any
 * shipment/order mutation, so duplicate tracking events, notifications
 * and order-completion actions are impossible even across concurrent
 * deliveries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipengine_webhook_calls', function (Blueprint $table) {
            $table->id();
            $table->string('resource_token')->index();   // shipment_id | label_id | tracking_number (whatever the payload carries)
            $table->string('event_type')->nullable();    // event per ShipEngine payload
            $table->json('payload')->nullable();         // raw body for audit — server-side only
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['resource_token', 'event_type'], 'shipengine_webhooks_unique_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipengine_webhook_calls');
    }
};
