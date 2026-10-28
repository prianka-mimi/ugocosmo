<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->text('customer_address')->nullable()->after('customer_dob');
        });
    }

    public function down(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropColumn('customer_address');
        });
    }
};
