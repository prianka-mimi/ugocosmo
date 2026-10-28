<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('secondary_title')->nullable();
            $table->string('icon_url');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('coin_cost')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'coin_cost']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};
