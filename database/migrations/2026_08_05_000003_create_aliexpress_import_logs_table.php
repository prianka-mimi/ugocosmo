<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aliexpress_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aliexpress_import_id')
                ->constrained('aliexpress_imports')
                ->cascadeOnDelete();
            $table->foreignId('aliexpress_account_id')
                ->constrained('aliexpress_accounts')
                ->cascadeOnDelete();

            $table->string('aliexpress_product_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('action'); // imported|updated|skipped|failed
            $table->string('reason')->nullable();
            $table->text('error_message')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index('aliexpress_product_id', 'idx_aliexpress_logs_product');
            $table->index('action', 'idx_aliexpress_logs_action');
            $table->index(
                ['aliexpress_import_id', 'action'],
                'idx_aliexpress_logs_import_action'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aliexpress_import_logs');
    }
};
