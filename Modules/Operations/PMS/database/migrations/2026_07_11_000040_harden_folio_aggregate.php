<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add new columns (nullable first for safe backfill) ────────────
        Schema::table('folios', function (Blueprint $table) {
            $table->unsignedInteger('window_number')->nullable()->after('guest_id');
            $table->string('opening_idempotency_key', 64)->nullable()->after('window_number');
        });

        // ── 2. Unique key on (property_id, id) for composite FK reference ──
        // id is the PK (ULID, globally unique), so (property_id, id) is
        // inherently unique. This constraint enables the composite FK from
        // folio_items(property_id, folio_id) → folios(property_id, id).
        Schema::table('folios', function (Blueprint $table) {
            $table->unique(['property_id', 'id'], 'folios_property_id_id_unique');
        });

        // ── 3. Backfill window_number per property + reservation ─────────────
        // Deterministic ordering: created_at ASC, then id ASC.
        // First folio per reservation = window 1, second = 2, etc.
        DB::statement(<<<'SQL'
            WITH ranked AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY property_id, reservation_id
                        ORDER BY created_at ASC, id ASC
                    ) AS rn
                FROM folios
            )
            UPDATE folios
            SET window_number = ranked.rn
            FROM ranked
            WHERE folios.id = ranked.id
        SQL);

        // ── 4. Backfill opening_idempotency_key ──────────────────────────────
        // Deterministic legacy values based on immutable folio IDs.
        // Format: legacy-{ulid} — ensures property-scoped uniqueness is
        // handled later via the unique constraint.
        DB::statement(<<<'SQL'
            UPDATE folios
            SET opening_idempotency_key = 'legacy-' || id
            WHERE opening_idempotency_key IS NULL
        SQL);

        // ── 5. Validate no orphan FolioItems ─────────────────────────────────
        $orphanCount = DB::table('folio_items')
            ->leftJoin('folios', 'folio_items.folio_id', '=', 'folios.id')
            ->whereNull('folios.id')
            ->count();

        if ($orphanCount > 0) {
            throw new \RuntimeException(
                "GLF_A_BLOCKED_MIGRATION_BACKFILL: Found {$orphanCount} orphan folio_items with no parent folio."
            );
        }

        // ── 6. Backfill folio_items.property_id from parent folio ────────────
        DB::statement(<<<'SQL'
            UPDATE folio_items
            SET property_id = folios.property_id
            FROM folios
            WHERE folio_items.folio_id = folios.id
              AND (folio_items.property_id IS NULL
                   OR folio_items.property_id <> folios.property_id)
        SQL);

        // ── 7. Validate no duplicate window identities ───────────────────────
        $dupWindows = DB::table('folios')
            ->selectRaw('property_id, reservation_id, window_number, COUNT(*) as cnt')
            ->groupBy('property_id', 'reservation_id', 'window_number')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($dupWindows > 0) {
            throw new \RuntimeException(
                "GLF_A_BLOCKED_MIGRATION_BACKFILL: Found {$dupWindows} duplicate window_number groups."
            );
        }

        // ── 8. Validate no duplicate idempotency keys ────────────────────────
        $dupKeys = DB::table('folios')
            ->selectRaw('property_id, opening_idempotency_key, COUNT(*) as cnt')
            ->groupBy('property_id', 'opening_idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($dupKeys > 0) {
            throw new \RuntimeException(
                "GLF_A_BLOCKED_MIGRATION_BACKFILL: Found {$dupKeys} duplicate opening_idempotency_key groups."
            );
        }

        // ── 9. Make new columns non-nullable ─────────────────────────────────
        Schema::table('folios', function (Blueprint $table) {
            $table->unsignedInteger('window_number')->nullable(false)->change();
            $table->string('opening_idempotency_key', 64)->nullable(false)->change();
        });

        // ── 9a. Positive window integrity check constraint ──────────────────
        DB::statement('ALTER TABLE folios ADD CONSTRAINT folios_window_number_positive_check CHECK (window_number > 0)');

        // ── 10. Add unique constraints ───────────────────────────────────────
        Schema::table('folios', function (Blueprint $table) {
            $table->unique(
                ['property_id', 'reservation_id', 'window_number'],
                'folios_property_reservation_window_unique'
            );
            $table->unique(
                ['property_id', 'opening_idempotency_key'],
                'folios_property_idempotency_key_unique'
            );
        });

        // ── 11. Index on (reservation_id, window_number) for ordered queries ─
        Schema::table('folios', function (Blueprint $table) {
            $table->index(
                ['reservation_id', 'window_number'],
                'folios_reservation_window_index'
            );
        });

        // ── 12. Add composite FK: folio_items(property_id, folio_id) → folios(property_id, id) ─
        Schema::table('folio_items', function (Blueprint $table) {
            $table->foreign(
                ['property_id', 'folio_id'],
                'folio_items_property_folio_foreign'
            )->references(['property_id', 'id'])->on('folios')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // ── Reverse: drop GLF-A additions only ───────────────────────────────

        Schema::table('folio_items', function (Blueprint $table) {
            $table->dropForeign('folio_items_property_folio_foreign');
        });

        Schema::table('folios', function (Blueprint $table) {
            $table->dropUnique('folios_property_reservation_window_unique');
            $table->dropUnique('folios_property_idempotency_key_unique');
            $table->dropIndex('folios_reservation_window_index');
            $table->dropUnique('folios_property_id_id_unique');
        });

        DB::statement('ALTER TABLE folios DROP CONSTRAINT IF EXISTS folios_window_number_positive_check');

        Schema::table('folios', function (Blueprint $table) {
            $table->dropColumn('window_number');
            $table->dropColumn('opening_idempotency_key');
        });
    }
};
