<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aliexpress_api_logs', function (Blueprint $table) {
            $table->boolean('is_sandbox')->default(false)->after('success');
            $table->json('signature_verification')->nullable()->after('response_preview');
            $table->index('is_sandbox', 'idx_aliexpress_api_logs_is_sandbox');
        });
    }

    public function down(): void
    {
        Schema::table('aliexpress_api_logs', function (Blueprint $table) {
            $table->dropIndex('idx_aliexpress_api_logs_is_sandbox');
            $table->dropColumn(['is_sandbox', 'signature_verification']);
        });
    }
};
