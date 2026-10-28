<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_conversations', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_group')->default(false);
            $table->string('name')->nullable();
            $table->string('avatar')->nullable();
            $table->timestamps();

            $table->index(['is_group', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_conversations');
    }
};
