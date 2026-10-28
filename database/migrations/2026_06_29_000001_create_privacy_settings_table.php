<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->index()->constrained('users')->cascadeOnDelete();

            // Age visibility
            // "private" = Only you and admins | "verified" = Visible to verified users
            $table->string('age_visibility')->nullable()->default('private');

            // Employment history visibility
            $table->string('employment_history_visibility')->nullable()->default('verified');

            // Qualifications visibility
            $table->string('qualifications_visibility')->nullable()->default('verified');

            // Profile visibility
            $table->string('profile_visibility')->nullable()->default('public');

            // Show online status
            $table->boolean('show_online_status')->nullable()->default(true);

            // Allow messages from
            // "everyone" | "connections" | "verified"
            $table->string('message_permission')->nullable()->default('everyone');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_settings');
    }
};
