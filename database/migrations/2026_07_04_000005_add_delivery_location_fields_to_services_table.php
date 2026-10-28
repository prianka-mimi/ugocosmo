<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('delivery_location')->nullable()->after('service_type');
            $table->text('address')->nullable()->after('location');
            $table->string('country')->nullable()->after('address');
            $table->string('city')->nullable()->after('country');
            $table->string('service_area')->nullable()->after('city');
            $table->json('areas_of_coverage')->nullable()->after('service_area');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_location',
                'address',
                'country',
                'city',
                'service_area',
                'areas_of_coverage',
            ]);
        });
    }
};
