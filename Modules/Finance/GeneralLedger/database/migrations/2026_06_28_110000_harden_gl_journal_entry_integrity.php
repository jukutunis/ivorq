<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required.');
        }

        // 1. Add check constraints to gl_journal_entry_lines
        DB::statement('ALTER TABLE gl_journal_entry_lines ADD CONSTRAINT chk_gl_jel_debit_amount_non_negative CHECK (debit_amount >= 0)');
        DB::statement('ALTER TABLE gl_journal_entry_lines ADD CONSTRAINT chk_gl_jel_credit_amount_non_negative CHECK (credit_amount >= 0)');
        DB::statement('ALTER TABLE gl_journal_entry_lines ADD CONSTRAINT chk_gl_jel_single_active_side CHECK ((debit_amount > 0 AND credit_amount = 0) OR (debit_amount = 0 AND credit_amount > 0))');

        // 2. Create trigger function for gl_journal_entries immutability
        DB::statement("
            CREATE OR REPLACE FUNCTION guard_gl_journal_entries_immutability()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.status = 'Posted' THEN
                        RAISE EXCEPTION 'Posted journal entries are immutable and cannot be updated.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                ELSIF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'Posted' THEN
                        RAISE EXCEPTION 'Posted journal entries are immutable and cannot be deleted.' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;
                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        // Attach triggers to gl_journal_entries
        DB::statement("
            CREATE TRIGGER trg_guard_gl_journal_entries_immutability
            BEFORE UPDATE OR DELETE ON gl_journal_entries
            FOR EACH ROW EXECUTE FUNCTION guard_gl_journal_entries_immutability();
        ");

        // 3. Create trigger function for gl_journal_entry_lines immutability
        DB::statement("
            CREATE OR REPLACE FUNCTION guard_gl_journal_entry_lines_immutability()
            RETURNS TRIGGER AS \$\$
            DECLARE
                parent_status TEXT;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT status INTO parent_status FROM gl_journal_entries WHERE id = NEW.journal_entry_id;
                    IF parent_status = 'Posted' THEN
                        RAISE EXCEPTION 'Cannot insert a line into a posted journal entry.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                ELSIF TG_OP = 'UPDATE' THEN
                    SELECT status INTO parent_status FROM gl_journal_entries WHERE id = OLD.journal_entry_id;
                    IF parent_status = 'Posted' THEN
                        RAISE EXCEPTION 'Cannot update a line of a posted journal entry.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                ELSIF TG_OP = 'DELETE' THEN
                    SELECT status INTO parent_status FROM gl_journal_entries WHERE id = OLD.journal_entry_id;
                    IF parent_status = 'Posted' THEN
                        RAISE EXCEPTION 'Cannot delete a line of a posted journal entry.' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;
                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        // Attach triggers to gl_journal_entry_lines
        DB::statement("
            CREATE TRIGGER trg_guard_gl_journal_entry_lines_immutability
            BEFORE INSERT OR UPDATE OR DELETE ON gl_journal_entry_lines
            FOR EACH ROW EXECUTE FUNCTION guard_gl_journal_entry_lines_immutability();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required.');
        }

        // Drop triggers and functions
        DB::statement('DROP TRIGGER IF EXISTS trg_guard_gl_journal_entry_lines_immutability ON gl_journal_entry_lines');
        DB::statement('DROP FUNCTION IF EXISTS guard_gl_journal_entry_lines_immutability()');

        DB::statement('DROP TRIGGER IF EXISTS trg_guard_gl_journal_entries_immutability ON gl_journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS guard_gl_journal_entries_immutability()');

        // Drop check constraints
        DB::statement('ALTER TABLE gl_journal_entry_lines DROP CONSTRAINT IF EXISTS chk_gl_jel_single_active_side');
        DB::statement('ALTER TABLE gl_journal_entry_lines DROP CONSTRAINT IF EXISTS chk_gl_jel_credit_amount_non_negative');
        DB::statement('ALTER TABLE gl_journal_entry_lines DROP CONSTRAINT IF EXISTS chk_gl_jel_debit_amount_non_negative');
    }
};
