<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required.');
        }

        Schema::table('gl_journal_entries', function (Blueprint $table) {
            $table->ulid('journal_candidate_id')->nullable();
            $table->string('posting_event')->nullable();

            $table->foreign('journal_candidate_id')
                  ->references('id')
                  ->on('journal_candidates')
                  ->restrictOnDelete();
        });

        // Partial unique index 1: one JournalEntry per non-null journal_candidate_id
        DB::statement('CREATE UNIQUE INDEX uk_gl_je_journal_candidate_id ON gl_journal_entries (journal_candidate_id) WHERE journal_candidate_id IS NOT NULL');

        // Partial unique index 2: one direct-subledger non-reversal journal per property_id + source_module + source_type + source_id
        // only where reversal_of_id IS NULL AND journal_candidate_id IS NULL AND source_module IS NOT NULL AND source_type IS NOT NULL AND source_id IS NOT NULL
        DB::statement('
            CREATE UNIQUE INDEX uk_gl_je_direct_subledger 
            ON gl_journal_entries (property_id, source_module, source_type, source_id) 
            WHERE reversal_of_id IS NULL 
              AND journal_candidate_id IS NULL 
              AND source_module IS NOT NULL 
              AND source_type IS NOT NULL 
              AND source_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required.');
        }

        DB::statement('DROP INDEX IF EXISTS uk_gl_je_direct_subledger');
        DB::statement('DROP INDEX IF EXISTS uk_gl_je_journal_candidate_id');

        Schema::table('gl_journal_entries', function (Blueprint $table) {
            $table->dropForeign(['journal_candidate_id']);
            $table->dropColumn(['journal_candidate_id', 'posting_event']);
        });
    }
};
