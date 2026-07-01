<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_executions', function (Blueprint $table) {
            $table->string('payment_intent_key', 120)->nullable()->after('payment_proposal_item_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_executions DROP CONSTRAINT IF EXISTS payment_executions_source_journal_entry_id_unique');
            DB::statement('CREATE UNIQUE INDEX payment_executions_payment_intent_key_unique ON payment_executions (payment_intent_key) WHERE payment_intent_key IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payment_executions_payment_intent_key_unique');
        }

        Schema::table('payment_executions', function (Blueprint $table) {
            $table->dropColumn('payment_intent_key');
            $table->unique('source_journal_entry_id');
        });
    }
};
