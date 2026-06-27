<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('movement_role');
            $table->char('financial_period_id', 26)->nullable()->after('currency_code');
            $table->string('valuation_scope')->nullable()->after('financial_period_id');
            $table->bigInteger('valuation_sequence')->unsigned()->nullable()->after('valuation_scope');
        });

        DB::statement('
            CREATE UNIQUE INDEX idx_inv_tx_valuation_sequence
            ON inventory_transactions (property_id, valuation_scope, valuation_sequence)
            WHERE valuation_sequence IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_inv_tx_valuation_sequence');

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'currency_code',
                'financial_period_id',
                'valuation_scope',
                'valuation_sequence'
            ]);
        });
    }
};
