<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aliexpress_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aliexpress_account_id')
                ->nullable()
                ->constrained('aliexpress_accounts')
                ->nullOnDelete();

            $table->string('type');   // category_sync | token_refresh | product_sync
            $table->string('status'); // started | completed | failed
            $table->string('message')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'created_at'], 'idx_aliexpress_sync_logs_type_status_created');
            $table->index(['aliexpress_account_id', 'created_at'], 'idx_aliexpress_sync_logs_account_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aliexpress_sync_logs');
    }
};
