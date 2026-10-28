<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aliexpress_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aliexpress_account_id')
                ->nullable()
                ->constrained('aliexpress_accounts')
                ->nullOnDelete();

            $table->string('method');      // Open Platform method or oauth action (token_create / token_refresh)
            $table->string('endpoint');    // URL path hit (e.g. /sync, /auth/token_create)
            $table->string('region')->default('sg');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('success')->default(true);
            $table->string('error_code')->nullable();   // AliExpress code / sub_code
            $table->text('error_message')->nullable();
            $table->json('param_keys')->nullable();     // request parameter KEYS only — never values
            $table->text('response_preview')->nullable(); // body excerpt, only captured for failed calls
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable(); // immutable audit log: no updated_at

            $table->index(['success', 'created_at'], 'idx_aliexpress_api_logs_success_created');
            $table->index(['aliexpress_account_id', 'created_at'], 'idx_aliexpress_api_logs_account_created');
            $table->index('method', 'idx_aliexpress_api_logs_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aliexpress_api_logs');
    }
};
