<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
