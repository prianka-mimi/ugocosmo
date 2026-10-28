<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aliexpress_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aliexpress_account_id')
                ->constrained('aliexpress_accounts')
                ->cascadeOnDelete();
            $table->foreignId('profile_id')
                ->constrained('profiles')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('status')->default('pending'); // pending|queued|processing|completed|partial|failed|cancelled
            $table->integer('total_products')->default(0);
            $table->integer('total_processed')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('failed_count')->default(0);

            // Only the products the seller explicitly selected are imported.
            $table->json('selected_product_ids')->nullable();
            $table->json('filters')->nullable();
            $table->json('errors')->nullable();
            $table->string('error_message')->nullable();
            $table->json('summary')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_aliexpress_imports_status');
            $table->index(
                ['aliexpress_account_id', 'status', 'created_at'],
                'idx_aliexpress_imports_account_status_created'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aliexpress_imports');
    }
};
