<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            $table->decimal('amount', 10, 2);
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('bank_account')->nullable();
            $table->foreignId('matched_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('matched_escrow_payment_id')->nullable()->constrained('escrow_payments')->nullOnDelete();
            $table->string('match_status')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignId('import_batch_id')->nullable();
            $table->string('file_format')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->string('file_format');
            $table->integer('total_transactions')->default(0);
            $table->integer('matched_count')->default(0);
            $table->integer('unmatched_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->foreignId('imported_by')->constrained('profiles')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_import_batches');
    }
};
