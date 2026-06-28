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

        // Add durable provenance columns.
        // Both are nullable: legacy CostAvcoState rows (bootstrapped by the consumer before
        // enrollment existed) remain valid and carry null provenance.
        DB::statement('ALTER TABLE cost_avco_states ADD COLUMN enrollment_group_id char(26) NULL');
        DB::statement('ALTER TABLE cost_avco_states ADD COLUMN enrollment_scope_snapshot_id char(26) NULL');

        // Safety: fail closed if any existing data would violate the snapshot uniqueness constraint.
        // For a fresh migration the column is new and all values are null; the check is defensive.
        $duplicates = DB::select("
            SELECT enrollment_scope_snapshot_id
            FROM cost_avco_states
            WHERE enrollment_scope_snapshot_id IS NOT NULL
            GROUP BY enrollment_scope_snapshot_id
            HAVING COUNT(*) > 1
            LIMIT 1
        ");

        if (!empty($duplicates)) {
            throw new \RuntimeException(
                'Migration blocked: duplicate enrollment_scope_snapshot_id values detected in cost_avco_states. ' .
                'Do not backfill or alter historical data.'
            );
        }

        // FK: enrollment_group_id → cost_authority_enrollment_groups.id
        // RESTRICT on delete: deleting an enrollment group is blocked while any seeded state references it.
        DB::statement('
            ALTER TABLE cost_avco_states
            ADD CONSTRAINT fk_cas_enrollment_group_id
            FOREIGN KEY (enrollment_group_id)
            REFERENCES cost_authority_enrollment_groups(id)
            ON DELETE RESTRICT
        ');

        // FK: enrollment_scope_snapshot_id → cost_authority_enrollment_scope_snapshots.id
        // RESTRICT on delete: deleting a snapshot is blocked while any seeded state references it.
        DB::statement('
            ALTER TABLE cost_avco_states
            ADD CONSTRAINT fk_cas_enrollment_scope_snapshot_id
            FOREIGN KEY (enrollment_scope_snapshot_id)
            REFERENCES cost_authority_enrollment_scope_snapshots(id)
            ON DELETE RESTRICT
        ');

        // Unique partial index: one scope snapshot may seed at most one CostAvcoState.
        // Group provenance (enrollment_group_id) is not unique: one group seeds multiple location states.
        DB::statement('
            CREATE UNIQUE INDEX uk_cas_enrollment_scope_snapshot_id
            ON cost_avco_states(enrollment_scope_snapshot_id)
            WHERE enrollment_scope_snapshot_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required for this migration.');
        }

        DB::statement('DROP INDEX IF EXISTS uk_cas_enrollment_scope_snapshot_id');
        DB::statement('ALTER TABLE cost_avco_states DROP CONSTRAINT IF EXISTS fk_cas_enrollment_scope_snapshot_id');
        DB::statement('ALTER TABLE cost_avco_states DROP CONSTRAINT IF EXISTS fk_cas_enrollment_group_id');
        DB::statement('ALTER TABLE cost_avco_states DROP COLUMN IF EXISTS enrollment_scope_snapshot_id');
        DB::statement('ALTER TABLE cost_avco_states DROP COLUMN IF EXISTS enrollment_group_id');
    }
};
