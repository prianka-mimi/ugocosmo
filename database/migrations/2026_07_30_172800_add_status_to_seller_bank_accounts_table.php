<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_bank_accounts', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('is_verified');
        });
    }

    public function down(): void
    {
        Schema::table('seller_bank_accounts', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
