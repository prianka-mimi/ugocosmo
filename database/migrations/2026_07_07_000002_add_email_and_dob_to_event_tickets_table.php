<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->after('customer_name');
            $table->date('customer_dob')->nullable()->after('customer_email');
        });
    }

    public function down(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropColumn(['customer_email', 'customer_dob']);
        });
    }
};
