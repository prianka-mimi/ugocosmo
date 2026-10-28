<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aliexpress_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('aliexpress_category_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('category_name');
            $table->string('locale', 8)->default('en');
            $table->boolean('is_leaf')->default(false);
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedInteger('children_count')->default(0);
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(
                ['aliexpress_category_id', 'locale'],
                'uq_aliexpress_categories_id_locale'
            );
            $table->index('parent_id', 'idx_aliexpress_categories_parent');
            $table->index('is_leaf', 'idx_aliexpress_categories_leaf');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aliexpress_categories');
    }
};
