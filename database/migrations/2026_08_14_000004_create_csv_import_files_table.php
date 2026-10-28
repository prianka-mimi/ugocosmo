<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-file tracking for multi-file CSV imports.
     *
     * One CsvImport row represents the whole import operation (aggregate
     * counts/status); each selected file gets one row here with its own
     * counts/status so individual file failures are identifiable without
     * ever mixing files' statistics into the aggregate counts.
     */
    public function up(): void
    {
        Schema::create('csv_import_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_import_id')
                ->constrained('csv_imports')
                ->cascadeOnDelete();

            $table->string('original_filename');
            $table->string('file_path');
            $table->string('status')->default('pending'); // pending|processing|completed|partial|failed|cancelled
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('failed_count')->default(0);

            $table->json('errors')->nullable();      // [{row, reason}]
            $table->string('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['csv_import_id', 'status'], 'idx_csv_import_files_import_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_files');
    }
};
