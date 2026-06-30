<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL is required.');
        }

        Schema::table('journal_candidates', function (Blueprint $table) {
            $table->ulid('source_grni_candidate_id')->nullable()->after('source_id');
            $table->ulid('source_grni_journal_entry_id')->nullable()->after('source_grni_candidate_id');
        });

        DB::statement("
            UPDATE journal_candidates
            SET source_grni_candidate_id = metadata->'source_grni'->>'candidate_id',
                source_grni_journal_entry_id = metadata->'source_grni'->>'journal_entry_id'
            WHERE posting_event = 'SupplierInvoiceGrniClearingApLiability'
              AND metadata IS NOT NULL
              AND metadata->'source_grni'->>'candidate_id' IS NOT NULL
              AND metadata->'source_grni'->>'journal_entry_id' IS NOT NULL
        ");

        $duplicates = DB::table('journal_candidates')
            ->select('property_id', 'posting_event', 'source_grni_journal_entry_id')
            ->where('posting_event', 'SupplierInvoiceGrniClearingApLiability')
            ->whereNotNull('source_grni_journal_entry_id')
            ->groupBy('property_id', 'posting_event', 'source_grni_journal_entry_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Migration failed: duplicate GRNI/AP clearing candidates exist for the same posted GRNI JournalEntry source.');
        }

        DB::statement("
            CREATE UNIQUE INDEX uk_journal_candidates_grni_ap_source_candidate
            ON journal_candidates (property_id, source_grni_candidate_id, posting_event)
            WHERE posting_event = 'SupplierInvoiceGrniClearingApLiability'
              AND source_grni_candidate_id IS NOT NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX uk_journal_candidates_grni_ap_source_journal
            ON journal_candidates (property_id, source_grni_journal_entry_id, posting_event)
            WHERE posting_event = 'SupplierInvoiceGrniClearingApLiability'
              AND source_grni_journal_entry_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL is required.');
        }

        DB::statement('DROP INDEX IF EXISTS uk_journal_candidates_grni_ap_source_journal');
        DB::statement('DROP INDEX IF EXISTS uk_journal_candidates_grni_ap_source_candidate');

        Schema::table('journal_candidates', function (Blueprint $table) {
            $table->dropColumn(['source_grni_candidate_id', 'source_grni_journal_entry_id']);
        });
    }
};
