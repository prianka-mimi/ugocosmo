<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores per-user notification preferences as a JSON object.
     * One row per user — preferences are created with defaults when first queried.
     *
     * Default preference structure (auto-created on first GET):
     *   {
     *     "booking":   { "push": true, "in_app": true },
     *     "ticket":    { "push": true, "in_app": true },
     *     "message":   { "push": true, "in_app": true },
     *     "livestream":{" push": true, "in_app": true }
     *   }
     *
     * The 'category' column maps notification types (e.g. 'booking.created')
     * to preference categories (e.g. 'booking'). New categories can be added
     * without a schema migration by extending the JSON structure.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete()->unique();
            $table->json('preferences');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
