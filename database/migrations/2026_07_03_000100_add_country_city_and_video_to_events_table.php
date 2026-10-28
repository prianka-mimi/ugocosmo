<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('country')->nullable()->after('description');
            $table->string('city')->nullable()->after('country');
            $table->string('ticket_prefix', 10)->nullable()->after('max_attendees');
            $table->string('currency', 10)->default('USD')->after('ticket_prefix');
            $table->string('video_url')->nullable()->after('image_url');
            $table->boolean('is_published')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'country',
                'city',
                'ticket_prefix',
                'currency',
                'video_url',
                'is_published',
            ]);
        });
    }
};
