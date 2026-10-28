<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Video support
            $table->string('video_url')->nullable()->after('image_url');

            // Attached item (product/service/event/job)
            $table->string('attached_item_type')->nullable()->after('video_url')->index();
            $table->unsignedBigInteger('attached_item_id')->nullable()->after('attached_item_type');

            // Multiple images support (original single image_url remains for backward compat)
            $table->json('media')->nullable()->after('attached_item_id');

            // GIF and emoji
            $table->string('gif_url')->nullable()->after('media');
            $table->string('emoji')->nullable()->after('gif_url');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['video_url', 'attached_item_type', 'attached_item_id', 'media', 'gif_url', 'emoji']);
        });
    }
};
