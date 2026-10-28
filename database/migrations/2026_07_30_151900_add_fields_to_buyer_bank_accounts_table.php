<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_bank_accounts', function (Blueprint $table) {
            $table->string('account_number')->nullable()->after('account_name');
            $table->string('sort_code')->nullable()->after('account_number_last4');
            $table->string('iban')->nullable()->after('sort_code');
            $table->string('swift_code')->nullable()->after('iban');
            $table->string('statement_path')->nullable()->after('swift_code');
            $table->string('status', 20)->default('pending')->after('statement_path');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_bank_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'account_number',
                'sort_code',
                'iban',
                'swift_code',
                'statement_path',
                'status',
            ]);
        });
    }
};
