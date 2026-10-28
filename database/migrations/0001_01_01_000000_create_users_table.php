<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('account_type')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable()->index()->unique();
            $table->string('email')->nullable()->index()->unique();
            $table->string('mobile_number')->nullable();
            $table->text('address_line_1')->nullable();
            $table->text('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('password')->nullable();
            $table->string('otp')->nullable()->index();
            $table->timestamp('otp_expires_at')->nullable();
            $table->string('new_email')->nullable()->index();
            $table->string('role')->nullable()->index();
            $table->string('avatar')->nullable();
            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->boolean('onboarding_completed')->default(false);
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->string('occupation')->nullable();
            $table->date('dob')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('bio')->nullable();
            $table->json('skills')->nullable();
            $table->json('interests')->nullable();
            $table->string('cv_resume')->nullable();
            $table->string('id_card_image')->nullable();
            $table->string('card_image')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->string('status')->default('pending');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload')->nullable();
            $table->integer('last_activity')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
