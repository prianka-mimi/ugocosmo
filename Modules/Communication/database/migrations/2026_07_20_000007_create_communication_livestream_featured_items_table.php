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
        Schema::create('communication_livestream_featured_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('call_log_id');
            $table->string('item_type'); // 'product', 'service', 'job', 'event'
            $table->unsignedBigInteger('item_id');
            $table->boolean('is_featured')->default(true);
            $table->timestamps();

            $table->foreign('call_log_id')
                ->references('id')
                ->on('call_logs')
                ->onDelete('cascade');

            $table->unique(['call_log_id', 'item_type', 'item_id'], 'unique_featured_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_livestream_featured_items');
    }
};
