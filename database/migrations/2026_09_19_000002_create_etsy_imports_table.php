<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import-run audit for Etsy, mirroring aliexpress_imports. Etsy has no
 * per-seller account concept (platform API key), so the run is scoped to
 * the importing profile/user directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etsy_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('profiles')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('status')->default('processing'); // processing|completed|partial|failed|cancelled
            $table->integer('total_products')->default(0);
            $table->integer('total_processed')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('failed_count')->default(0);

            // Only the listings the seller explicitly selected are imported.
            $table->json('selected_listing_ids')->nullable();
            $table->json('filters')->nullable();
            $table->json('errors')->nullable();
            $table->string('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_etsy_imports_status');
            $table->index(
                ['profile_id', 'status', 'created_at'],
                'idx_etsy_imports_profile_status_created'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etsy_imports');
    }
};
