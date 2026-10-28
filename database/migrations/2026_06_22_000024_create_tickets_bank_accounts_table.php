<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('ticket_number', 50);
            $table->string('customer_name');
            $table->string('status', 20)->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number');
            $table->string('sort_code')->nullable();
            $table->string('iban')->nullable();
            $table->string('swift_code')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });

        Schema::create('buyer_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number_last4');
            $table->boolean('is_own_account')->default(false);
            $table->timestamp('declaration_signed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tickets');
        Schema::dropIfExists('seller_bank_accounts');
        Schema::dropIfExists('buyer_bank_accounts');
    }
};
