<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->string('category')->nullable();
            $table->string('product_type')->nullable();
            $table->string('image_url')->nullable();
            $table->json('images')->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('digital_download_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
