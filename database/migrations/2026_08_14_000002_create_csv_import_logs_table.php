<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_import_id')
                ->constrained('csv_imports')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('row_number')->nullable();
            $table->string('file_name')->nullable(); // source file for multi-file imports
            $table->string('sku')->nullable();
            $table->string('product_title')->nullable();
            $table->string('action'); // imported|updated|skipped|failed
            $table->string('reason')->nullable();
            $table->text('error_message')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index('row_number', 'idx_csv_logs_row');
            $table->index('file_name', 'idx_csv_logs_file');
            $table->index('action', 'idx_csv_logs_action');
            $table->index(
                ['csv_import_id', 'action'],
                'idx_csv_logs_import_action'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_logs');
    }
};
