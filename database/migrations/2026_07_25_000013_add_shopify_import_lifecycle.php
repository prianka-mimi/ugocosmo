<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_imports', function (Blueprint $table) {
            // ─── Import locking (prevent concurrent imports per store) ───
            if (! Schema::hasColumn('shopify_imports', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('shopify_imports', 'locked_by')) {
                $table->string('locked_by', 100)->nullable()->after('locked_at');
            }

            // ─── Resume / Retry support ──────────────────────────────
            if (! Schema::hasColumn('shopify_imports', 'last_processed_product_id')) {
                $table->string('last_processed_product_id', 255)
                    ->nullable()
                    ->after('selected_product_ids');
            }
            if (! Schema::hasColumn('shopify_imports', 'resumed_from_id')) {
                $table->foreignId('resumed_from_id')
                    ->nullable()
                    ->constrained('shopify_imports')
                    ->nullOnDelete()
                    ->after('last_processed_product_id');
            }
            if (! Schema::hasColumn('shopify_imports', 'retry_of_id')) {
                $table->foreignId('retry_of_id')
                    ->nullable()
                    ->constrained('shopify_imports')
                    ->nullOnDelete()
                    ->after('resumed_from_id');
            }

            // ─── Progress tracking ───────────────────────────────────
            if (! Schema::hasColumn('shopify_imports', 'total_processed')) {
                $table->integer('total_processed')->default(0)->after('total_products');
            }

            // ─── Audit control ───────────────────────────────────────
            if (! Schema::hasColumn('shopify_imports', 'logs_enabled')) {
                $table->boolean('logs_enabled')->default(true)->after('summary');
            }
        });

        // ─── Index for finding imports to resume/retry ──────────────────
        Schema::table('shopify_imports', function (Blueprint $table) {
            $table->index(
                ['shopify_store_id', 'status', 'locked_at', 'id'],
                'idx_shopify_imports_lock'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shopify_imports', function (Blueprint $table) {
            $table->dropIndex('idx_shopify_imports_lock');

            if (Schema::hasColumn('shopify_imports', 'retry_of_id')) {
                $table->dropForeign(['retry_of_id']);
            }
            if (Schema::hasColumn('shopify_imports', 'resumed_from_id')) {
                $table->dropForeign(['resumed_from_id']);
            }

            $table->dropColumn([
                'locked_at',
                'locked_by',
                'last_processed_product_id',
                'resumed_from_id',
                'retry_of_id',
                'total_processed',
                'logs_enabled',
            ]);
        });
    }
};
