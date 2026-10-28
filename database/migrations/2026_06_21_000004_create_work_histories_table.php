<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('position_title')->nullable();
            $table->date('start_date')->nullable()->index();
            $table->date('end_date')->nullable();
            $table->text('job_description')->nullable();
            $table->boolean('is_currently_working')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_histories');
    }
};
