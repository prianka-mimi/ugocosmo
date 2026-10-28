<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend shipments with the rate selection reference, seller scoping and
 * address/package snapshots used by the webhook and audit flows.
 *
 * ship_from_snapshot: origin actually used (today the configured
 * SHIPENGINE_SHIP_FROM; per-warehouse origins when that becomes real).
 * ship_to_snapshot / package_snapshot: exact data sent to ShipEngine so
 * any shipment can be reproduced/audited without re-deriving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Selected rate reference (mirrors orders.selected_rate_id).
            $table->string('rate_id')->nullable()->index()->after('order_id');

            // Seller scope — the order IS the seller order in this schema,
            // but the column enables direct seller-scoped queries without a
            // join (mirrors products.seller_id usage).
            $table->foreignId('seller_id')
                ->nullable()
                ->after('order_id')
                ->constrained('profiles')
                ->nullOnDelete();

            // Snapshots for audit/reproduction (JSON).
            $table->json('ship_from_snapshot')->nullable();
            $table->json('ship_to_snapshot')->nullable();
            $table->json('package_snapshot')->nullable();

            // Lifecycle timestamps driven by carrier events.
            $table->timestamp('label_created_at')->nullable()->after('voided_at');

            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn([
                'rate_id',
                'ship_from_snapshot',
                'ship_to_snapshot',
                'package_snapshot',
                'label_created_at',
            ]);
        });
    }
};
