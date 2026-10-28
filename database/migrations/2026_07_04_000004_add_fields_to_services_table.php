<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Replace string category with foreign key to service_categories
            $table->dropColumn('category');
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();

            // New fields
            $table->string('currency', 10)->default('USD')->after('price');
            $table->string('estimated_delivery_time', 100)->nullable()->after('service_type');
            $table->boolean('is_insured')->default(false)->after('estimated_delivery_time');
            $table->boolean('is_certified')->default(false)->after('is_insured');
            $table->string('video_url')->nullable()->after('image_url');
            $table->json('images')->nullable()->after('video_url');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'category_id',
                'currency',
                'estimated_delivery_time',
                'is_insured',
                'is_certified',
                'video_url',
                'images',
            ]);
            $table->string('category')->nullable()->after('service_type');
        });
    }
};
