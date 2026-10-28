<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add livestream support on top of the existing call_logs table.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE call_logs MODIFY call_type ENUM('audio','video','stream') NOT NULL");
        }

        Schema::table('call_logs', function (Blueprint $table): void {
            $table->string('title', 191)
                ->nullable()
                ->after('room_name')
                ->comment('Livestream title');

            $table->text('description')
                ->nullable()
                ->after('title')
                ->comment('Livestream optional description');

            $table->string('thumbnail_url', 512)
                ->nullable()
                ->after('description')
                ->comment('Livestream cover/thumbnail');

            $table->string('background_image_url', 512)->nullable()->after('thumbnail_url');

            $table->string('video_url', 512)->nullable()->after('background_image_url');

            $table->string('stream_layout', 32)
                ->default('solo')
                ->after('video_url')
                ->comment('Livestream layout: solo, duo, group, party, stage');

            $table->boolean('allow_gift_bet')
                ->default(false)
                ->after('stream_layout')
                ->comment('Allow viewers to bet gifts');

            $table->json('custom_questions')
                ->nullable()
                ->after('allow_gift_bet')
                ->comment('Custom game questions list');

            $table->boolean('is_live')
                ->default(false)
                ->after('custom_questions')
                ->comment('true while a stream is active');

            $table->unsignedInteger('viewer_count')
                ->default(0)
                ->after('is_live')
                ->comment('Cached live viewer count for the X watching ticker');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'title',
                'description',
                'thumbnail_url',
                'stream_layout',
                'allow_gift_bet',
                'custom_questions',
                'is_live',
                'viewer_count',
            ]);
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE call_logs MODIFY call_type ENUM('audio','video') NOT NULL");
        }
    }
};
