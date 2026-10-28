<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('status');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('business_name')->nullable();
            $table->text('business_description')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('town')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('photo_id_url')->nullable();
            $table->string('proof_of_address_url')->nullable();
            $table->string('selfie_url')->nullable();
            $table->string('business_insurance_url')->nullable();
            $table->string('certification_url')->nullable();
            $table->string('branding_license_url')->nullable();
            $table->string('incorporation_certificate_url')->nullable();
            $table->text('admin_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('profiles')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_submissions');
    }
};
