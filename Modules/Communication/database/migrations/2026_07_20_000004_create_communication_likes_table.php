<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('likeable');
            $table->timestamps();

            $table->unique(['user_id', 'likeable_type', 'likeable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_likes');
    }
};
