<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 50)->unique();              // 'admin', 'supervisor'
            $table->json('permissions')->nullable();            // ["users.view", "users.create", ...]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
