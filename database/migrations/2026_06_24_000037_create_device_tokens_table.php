<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores Firebase Cloud Messaging (FCM) device tokens per user profile.
     * - fcm_token: The device registration token from FCM SDK on Android/iOS/Web.
     * - device_type: 'android', 'ios', or 'web' for targeting formatted pushes.
     * - is_active: Soft toggle so we don't lose history of previously registered tokens.
     *
     * When FCM returns a 404/NotRegistered error for a token, we set is_active=false
     * instead of deleting, so the user can re-register from the same device.
     */
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('fcm_token', 500)->unique();              // FCM registration token
            $table->string('device_type', 20)->default('android');    // android | ios | web
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Speed up lookups by user (send push to all active tokens)
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
