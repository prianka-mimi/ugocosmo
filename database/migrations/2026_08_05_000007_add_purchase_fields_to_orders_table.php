<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Seller's manual AliExpress purchase record. The seller buys
            // from AliExpress themselves — nothing here is automated.
            $table->string('purchase_reference')->nullable()->after('tracking_number');
            $table->string('supplier_name')->nullable()->after('purchase_reference');
            $table->decimal('supplier_cost', 12, 2)->nullable()->after('supplier_name');
            $table->date('purchase_date')->nullable()->after('supplier_cost');
            $table->text('purchase_notes')->nullable()->after('purchase_date');

            // Fulfillment milestone timestamps.
            $table->timestamp('purchased_at')->nullable()->after('purchase_notes');
            $table->timestamp('stock_received_at')->nullable()->after('purchased_at');
            $table->timestamp('shipped_at')->nullable()->after('stock_received_at');
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');

            $table->index('status', 'idx_orders_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_status');
            $table->dropColumn([
                'purchase_reference',
                'supplier_name',
                'supplier_cost',
                'purchase_date',
                'purchase_notes',
                'purchased_at',
                'stock_received_at',
                'shipped_at',
                'delivered_at',
            ]);
        });
    }
};
