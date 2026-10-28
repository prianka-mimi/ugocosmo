<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('profiles')->cascadeOnDelete();
            $table->text('content');
            $table->string('image_url')->nullable();
            $table->integer('like_count')->default(0);
            $table->integer('comment_count')->default(0);
            $table->integer('share_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_reported')->default(false);
            $table->timestamps();
        });

        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('profiles')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
        });

        Schema::create('post_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('post_likes');
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_saves');
    }
};
