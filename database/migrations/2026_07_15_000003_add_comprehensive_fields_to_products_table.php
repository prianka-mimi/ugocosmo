<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Replace string category with foreign key to product_categories
            $table->dropColumn('category');

            // Drop JSON tags (we use relationship with product_tags now)
            $table->dropColumn('tags');

            // ── Foreign Keys ──────────────────────────────────────
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();

            // ── 1. Basic Information extensions ───────────────────
            $table->json('product_variants')->nullable()->after('condition');

            // ── 2. Details ───────────────────────────────────────
            $table->longText('specifications')->nullable()->after('description');
            $table->longText('features_benefits')->nullable()->after('specifications');
            $table->boolean('wholesale_product')->default(false)->after('weight');

            // ── 3. Shipping ──────────────────────────────────────
            $table->string('ship_from_address')->nullable()->after('location');
            $table->json('shipping_options')->nullable()->after('ship_from_address');
            $table->decimal('shipping_cost', 10, 2)->nullable()->after('shipping_options');
            $table->json('package_dimensions')->nullable()->after('shipping_cost');
            $table->string('handling_time')->nullable()->after('package_dimensions');
            $table->string('estimated_delivery')->nullable()->after('handling_time');

            // ── 4. Media & Status ────────────────────────────────
            $table->string('video_url')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'category_id',
                'product_variants',
                'specifications',
                'features_benefits',
                'wholesale_product',
                'ship_from_address',
                'shipping_options',
                'shipping_cost',
                'package_dimensions',
                'handling_time',
                'estimated_delivery',
                'video_url',
            ]);
            $table->string('category')->nullable()->after('product_type');
            $table->json('tags')->nullable()->after('images');
        });
    }
};
