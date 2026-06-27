<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required.');
        }

        // 1. Reclassify POSTED status to POSTED_LEGACY
        DB::table('journal_candidates')
            ->where('status', 'POSTED')
            ->update(['status' => 'POSTED_LEGACY']);

        // 2. Check for duplicate rows before adding unique index
        $duplicates = DB::table('journal_candidates')
            ->select('property_id', 'source_type', 'source_id', 'posting_event')
            ->groupBy('property_id', 'source_type', 'source_id', 'posting_event')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new \RuntimeException('Migration failed: duplicate journal candidates exist for property_id, source_type, source_id, posting_event.');
        }

        // 3. Create unique index
        DB::statement('CREATE UNIQUE INDEX uk_journal_candidates_canonical_identity ON journal_candidates (property_id, source_type, source_id, posting_event)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required.');
        }

        // Drop unique index
        DB::statement('DROP INDEX IF EXISTS uk_journal_candidates_canonical_identity');

        // Restore status values back to POSTED
        DB::table('journal_candidates')
            ->where('status', 'POSTED_LEGACY')
            ->update(['status' => 'POSTED']);
    }
};
