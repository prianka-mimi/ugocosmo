<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('profiles')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('status')->default('pending'); // pending|queued|processing|completed|partial|failed|cancelled
            $table->string('original_filename')->nullable(); // single-file uploads; multi-file uploads use csv_import_files
            $table->string('file_path')->nullable();
            $table->integer('total_rows')->default(0); // aggregate across all files
            $table->integer('processed_rows')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->double('progress_percentage')->default(0);

            // Import options (category default, currency, image download, ...).
            $table->json('options')->nullable();
            $table->json('errors')->nullable();
            $table->string('error_message')->nullable();
            $table->json('summary')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_csv_imports_status');
            $table->index(
                ['user_id', 'status', 'created_at'],
                'idx_csv_imports_user_status_created'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_imports');
    }
};
