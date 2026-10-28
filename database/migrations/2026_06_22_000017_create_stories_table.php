<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('media_url');
            $table->string('media_type');
            $table->integer('duration_seconds')->nullable();
            $table->text('caption')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('view_count')->default(0);
            $table->timestamps();
        });

        Schema::create('story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewer_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('story_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('cover_url')->nullable();
            $table->timestamps();
        });

        Schema::create('story_highlight_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('highlight_id')->constrained('story_highlights')->cascadeOnDelete();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->integer('display_order')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('story_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reactor_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('reaction_type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
        Schema::dropIfExists('story_views');
        Schema::dropIfExists('story_highlights');
        Schema::dropIfExists('story_highlight_items');
        Schema::dropIfExists('story_reactions');
    }
};
