<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_proposal_items', function (Blueprint $table) {
            $table->decimal('original_source_amount', 19, 2)->nullable()->after('source_amount');
            $table->decimal('requested_payment_amount', 19, 2)->nullable()->after('original_source_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_proposal_items', function (Blueprint $table) {
            $table->dropColumn([
                'original_source_amount',
                'requested_payment_amount',
            ]);
        });
    }
};
