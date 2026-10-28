<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dropshipping via the AliExpress Open Platform:
 *
 *  aliexpress_order_numbers — AE order numbers returned by
 *      aliexpress.ds.order.create (one create call can yield several orders,
 *      one per seller/warehouse on AliExpress side).
 *  aliexpress_placed_at     — when the supplier order was placed.
 *  aliexpress_logistics     — last synced supplier logistics snapshot from
 *      aliexpress.trade.ds.order.get (tracking numbers, logistics status,
 *      synced_at) used by aliexpress:sync-tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('aliexpress_order_numbers')->nullable()->after('tracking_number');
            $table->timestamp('aliexpress_placed_at')->nullable()->after('aliexpress_order_numbers');
            $table->json('aliexpress_logistics')->nullable()->after('aliexpress_placed_at');
            $table->index('aliexpress_placed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['aliexpress_placed_at']);
            $table->dropColumn(['aliexpress_order_numbers', 'aliexpress_placed_at', 'aliexpress_logistics']);
        });
    }
};
