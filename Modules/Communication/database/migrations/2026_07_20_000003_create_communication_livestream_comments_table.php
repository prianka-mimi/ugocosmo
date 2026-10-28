<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_livestream_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_log_id')->constrained('call_logs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('communication_livestream_comments')
                ->cascadeOnDelete();
            $table->text('comment');
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('replies_count')->default(0);
            $table->timestamps();

            $table->index('call_log_id');
            $table->index('user_id');
            $table->index(['call_log_id', 'created_at']);
        });

        Schema::table('call_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('call_logs', 'likes_count')) {
                $table->unsignedBigInteger('likes_count')->default(0)->after('viewer_count');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_livestream_comments');

        Schema::table('call_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('call_logs', 'likes_count')) {
                $table->dropColumn('likes_count');
            }
        });
    }
};
