<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('post_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('reaction', 20); // like, love, haha, wow, sad, angry
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']); // One reaction per user per comment
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_reactions');
    }
};
