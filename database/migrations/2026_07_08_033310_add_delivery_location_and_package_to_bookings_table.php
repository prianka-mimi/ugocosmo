<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('delivery_location')->nullable()->after('booking_time');
            $table->text('customer_address')->nullable()->after('delivery_location');
            $table->foreignId('package_id')->nullable()->after('customer_address')->constrained('service_packages')->nullOnDelete();
            $table->integer('duration')->default(1)->after('package_id');
            $table->json('images')->nullable()->after('duration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn([
                'delivery_location',
                'customer_address',
                'package_id',
                'duration',
                'images',
            ]);
        });
    }
};
