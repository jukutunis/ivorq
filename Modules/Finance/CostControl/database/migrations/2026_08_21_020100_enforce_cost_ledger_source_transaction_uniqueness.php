<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'uk_cost_ledger_source_inventory_transaction';

    public function up(): void
    {
        $duplicate = DB::table('cost_ledger_entries')
            ->select('source_inventory_transaction_id')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->whereNotNull('source_inventory_transaction_id')
            ->groupBy('source_inventory_transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('source_inventory_transaction_id')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(sprintf(
                'CC_P01C_COST_LEDGER_SOURCE_DUPLICATES_EXIST source_inventory_transaction_id=%s count=%d',
                $duplicate->source_inventory_transaction_id,
                $duplicate->duplicate_count,
            ));
        }

        DB::statement(sprintf(
            'ALTER TABLE cost_ledger_entries ADD CONSTRAINT %s UNIQUE (source_inventory_transaction_id)',
            self::CONSTRAINT,
        ));
    }

    public function down(): void
    {
        DB::statement(sprintf(
            'ALTER TABLE cost_ledger_entries DROP CONSTRAINT IF EXISTS %s',
            self::CONSTRAINT,
        ));
    }
};
