<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('full_name')->nullable();
            $table->string('username')->nullable();
            $table->string('avatar_url')->nullable();
            $table->text('bio')->nullable();
            $table->string('location')->nullable();
            $table->string('website')->nullable();
            $table->string('subscription_tier')->nullable();
            $table->timestamp('subscription_expires_at')->nullable();
            $table->boolean('is_seller')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
