<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignId('seller_id')
                ->constrained('profiles')
                ->cascadeOnDelete();
            $table->foreignId('aliexpress_account_id')
                ->nullable()
                ->constrained('aliexpress_accounts')
                ->nullOnDelete();

            // receive | adjustment | reserve | release | ship | restock
            $table->string('type');

            // Signed delta applied to the relevant counter (stock or reserved).
            $table->integer('quantity')->default(0);

            // Before/after snapshots for auditability.
            $table->unsignedInteger('stock_before')->default(0);
            $table->unsignedInteger('stock_after')->default(0);
            $table->unsignedInteger('reserved_before')->default(0);
            $table->unsignedInteger('reserved_after')->default(0);

            // Polymorphic reference (e.g. order / order_item).
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // profile id of the actor
            $table->timestamps();

            $table->index('product_id', 'idx_inventory_tx_product');
            $table->index('seller_id', 'idx_inventory_tx_seller');
            $table->index('type', 'idx_inventory_tx_type');
            $table->index(
                ['reference_type', 'reference_id'],
                'idx_inventory_tx_reference'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
