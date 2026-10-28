<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link imported Etsy products and import runs to the connected Etsy
     * account — the same-to-same ownership model as AliExpress
     * (products.aliexpress_account_id / aliexpress_imports.aliexpress_account_id).
     *
     * Rows created before this change (platform-key era) keep NULL and remain
     * owned by products.seller_id / etsy_imports.profile_id.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('etsy_account_id')
                ->nullable()
                ->after('etsy_listing_id')
                ->constrained('etsy_accounts')
                ->nullOnDelete();
        });

        Schema::table('etsy_imports', function (Blueprint $table) {
            $table->foreignId('etsy_account_id')
                ->nullable()
                ->after('profile_id')
                ->constrained('etsy_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('etsy_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('etsy_account_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('etsy_account_id');
        });
    }
};
