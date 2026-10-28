<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_livestream_gamification_presets', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 10, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'comm_stream_gamification_active_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_livestream_gamification_presets');
    }
};
