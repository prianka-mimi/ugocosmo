<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saved_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('label')->nullable();          // e.g. "Home", "Office"
            $table->string('name');                        // Recipient name
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city_locality');
            $table->string('state_province')->nullable();
            $table->string('postal_code');
            $table->string('country_code', 10)->default('US');
            $table->string('phone')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['profile_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_addresses');
    }
};
