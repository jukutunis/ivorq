<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX folio_items_deposit_app_source_unique
ON folio_items (property_id, guest_deposit_application_id)
WHERE guest_deposit_application_id IS NOT NULL AND item_type = 'deposit'
SQL);

        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX folio_items_deposit_reversal_source_unique
ON folio_items (property_id, guest_deposit_reversal_id)
WHERE guest_deposit_reversal_id IS NOT NULL AND item_type = 'deposit_reversal'
SQL);

        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX folio_items_ar_decision_source_unique
ON folio_items (property_id, guest_ar_transfer_decision_id)
WHERE guest_ar_transfer_decision_id IS NOT NULL
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS folio_items_deposit_app_source_unique');
        DB::statement('DROP INDEX IF EXISTS folio_items_deposit_reversal_source_unique');
        DB::statement('DROP INDEX IF EXISTS folio_items_ar_decision_source_unique');
    }
};
