<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_submissions', function (Blueprint $table) {
            $table->string('stripe_payment_intent_id')->nullable()->after('subscription_plan_id');
            $table->string('payment_status')->default('pending')->after('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('verification_submissions', function (Blueprint $table) {
            $table->dropColumn(['stripe_payment_intent_id', 'payment_status']);
        });
    }
};
