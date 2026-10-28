<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            // OrderStatus value this event represents.
            $table->string('status');
            // buyer | seller | admin | system
            $table->string('actor_role')->default('system');
            $table->foreignId('actor_profile_id')
                ->nullable()
                ->constrained('profiles')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            // Events are immutable — no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at'], 'idx_order_timeline_order_created');
        });

        // Backfill: give every existing order a baseline event at its
        // creation time, reflecting the status we currently know.
        DB::table('orders')
            ->select(['id', 'status', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($orders) {
                $events = [];
                foreach ($orders as $order) {
                    $events[] = [
                        'order_id' => $order->id,
                        'status' => $order->status ?: 'pending',
                        'actor_role' => 'system',
                        'actor_profile_id' => null,
                        'notes' => 'Order baseline (backfilled)',
                        'metadata' => null,
                        'created_at' => $order->created_at ?? now(),
                    ];
                }
                DB::table('order_timeline_events')->insert($events);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_timeline_events');
    }
};
