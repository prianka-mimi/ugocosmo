<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-listing audit rows for an Etsy import run, mirroring
 * aliexpress_import_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etsy_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etsy_import_id')
                ->constrained('etsy_imports')
                ->cascadeOnDelete();
            $table->foreignId('profile_id')
                ->constrained('profiles')
                ->cascadeOnDelete();

            $table->string('etsy_listing_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('action'); // imported|updated|skipped|failed
            $table->string('reason')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('etsy_listing_id', 'idx_etsy_logs_listing');
            $table->index('action', 'idx_etsy_logs_action');
            $table->index(
                ['etsy_import_id', 'action'],
                'idx_etsy_logs_import_action'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etsy_import_logs');
    }
};
