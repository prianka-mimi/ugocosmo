<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── shopify_webhook_calls: idempotency + dead-letter tracking ───
        Schema::table('shopify_webhook_calls', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_webhook_calls', 'webhook_id')) {
                $table->string('webhook_id', 100)
                    ->nullable()
                    ->after('shop_domain')
                    ->comment('X-Shopify-Webhook-Id header for idempotency');
            }
            if (! Schema::hasColumn('shopify_webhook_calls', 'processing_attempts')) {
                $table->tinyInteger('processing_attempts')
                    ->unsigned()
                    ->default(0)
                    ->after('status')
                    ->comment('Number of processing attempts');
            }
            if (! Schema::hasColumn('shopify_webhook_calls', 'dead_lettered_at')) {
                $table->timestamp('dead_lettered_at')
                    ->nullable()
                    ->after('processing_attempts')
                    ->comment('When this webhook was moved to dead letter (max retries exceeded)');
            }
            if (! Schema::hasColumn('shopify_webhook_calls', 'processed_at')) {
                $table->timestamp('processed_at')
                    ->nullable()
                    ->after('dead_lettered_at')
                    ->comment('When this webhook was successfully processed');
            }

            // Index for idempotency lookups
            $table->index('webhook_id', 'idx_webhook_id');
            $table->index(
                ['topic', 'status', 'created_at'],
                'idx_webhook_topic_status_created'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shopify_webhook_calls', function (Blueprint $table) {
            $table->dropIndex('idx_webhook_id');
            $table->dropIndex('idx_webhook_topic_status_created');

            $columns = ['webhook_id', 'processing_attempts', 'dead_lettered_at', 'processed_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('shopify_webhook_calls', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
