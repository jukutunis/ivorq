<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required for this migration.');
        }

        // Safety: fail closed if historical duplicate reversal rows already exist.
        $duplicates = DB::select("
            SELECT reversal_of_id
            FROM gl_journal_entries
            WHERE reversal_of_id IS NOT NULL
            GROUP BY reversal_of_id
            HAVING COUNT(*) > 1
            LIMIT 1
        ");

        if (!empty($duplicates)) {
            throw new \RuntimeException(
                'Migration blocked: historical duplicate reversal_of_id values detected in gl_journal_entries. ' .
                'Do not backfill, merge, delete, or rewrite historical journals.'
            );
        }

        // 1. FK: reversal_of_id → gl_journal_entries.id, RESTRICT on delete.
        //    Prevents creating a reversal that points to a non-existent journal.
        //    Deleting an original journal is blocked while any reversal references it.
        DB::statement('
            ALTER TABLE gl_journal_entries
            ADD CONSTRAINT fk_gl_je_reversal_of_id
            FOREIGN KEY (reversal_of_id)
            REFERENCES gl_journal_entries(id)
            ON DELETE RESTRICT
        ');

        // 2. Unique partial index: at most one reversal per original journal.
        DB::statement('
            CREATE UNIQUE INDEX uk_gl_je_one_reversal_per_original
            ON gl_journal_entries(reversal_of_id)
            WHERE reversal_of_id IS NOT NULL
        ');

        // 3. Trigger function: validate reversal business rules before insert or update.
        DB::statement("
            CREATE OR REPLACE FUNCTION guard_gl_journal_reversal_integrity()
            RETURNS TRIGGER AS \$\$
            DECLARE
                orig_status       TEXT;
                orig_reversal_id  CHAR(26);
            BEGIN
                -- A journal cannot reverse itself.
                IF NEW.reversal_of_id = NEW.id THEN
                    RAISE EXCEPTION 'A journal entry cannot reverse itself (id=%).',
                        NEW.id USING ERRCODE = '23514';
                END IF;

                -- Load the referenced original.
                SELECT status, reversal_of_id
                INTO orig_status, orig_reversal_id
                FROM gl_journal_entries
                WHERE id = NEW.reversal_of_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Reversal target does not exist (reversal_of_id=%).',
                        NEW.reversal_of_id USING ERRCODE = '23503';
                END IF;

                -- Original must be Posted.
                IF orig_status <> 'Posted' THEN
                    RAISE EXCEPTION 'Cannot reverse a non-Posted journal entry (id=%, status=%).',
                        NEW.reversal_of_id, orig_status USING ERRCODE = '23514';
                END IF;

                -- Original must not itself be a reversal.
                IF orig_reversal_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Cannot reverse a reversal journal entry (id=%).',
                        NEW.reversal_of_id USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        // Attach to gl_journal_entries: fires on INSERT and on UPDATE when reversal_of_id changes.
        DB::statement("
            CREATE TRIGGER trg_guard_gl_journal_reversal_integrity
            BEFORE INSERT OR UPDATE OF reversal_of_id ON gl_journal_entries
            FOR EACH ROW
            WHEN (NEW.reversal_of_id IS NOT NULL)
            EXECUTE FUNCTION guard_gl_journal_reversal_integrity();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required for this migration.');
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_guard_gl_journal_reversal_integrity ON gl_journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS guard_gl_journal_reversal_integrity()');
        DB::statement('DROP INDEX IF EXISTS uk_gl_je_one_reversal_per_original');
        DB::statement('ALTER TABLE gl_journal_entries DROP CONSTRAINT IF EXISTS fk_gl_je_reversal_of_id');
    }
};
