<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use PDO;
use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimSegregationMigrationProofTest extends PostgresTestCase
{
    private const PREFIX = 'ivorq_testing_hk_p17_migration_';
    private const MIGRATION = 'Modules/Operations/Housekeeping/database/migrations/2026_08_11_000001_control_housekeeping_inspection_claims.php';

    public function test_disposable_postgresql_up_down_reapply_legacy_compatibility_and_raw_integrity(): void
    {
        $originalDatabase = config('database.connections.pgsql.database');
        $database = self::PREFIX . strtolower((string) Str::ulid());
        $admin = $this->adminPdo();
        $admin->exec('CREATE DATABASE ' . $this->quoteIdentifier($database));

        try {
            $this->switchDatabase($database);
            Artisan::call('migrate', ['--force' => true]);
            $migration = require base_path(self::MIGRATION);
            $migration->down();

            $graph = $this->graph();
            $historical = [
                'in_progress' => $this->additionalHistoricalInspection($graph, 'P17-M-LEGACY-IP', 'in_progress'),
                'passed' => $this->additionalHistoricalInspection($graph, 'P17-M-LEGACY-PASS', 'passed'),
                'failed' => $this->additionalHistoricalInspection($graph, 'P17-M-LEGACY-FAIL', 'failed'),
                'cleaner_pass_rejection' => $this->additionalHistoricalInspection($graph, 'P17-M-LC-P', 'in_progress', $graph['cleaner']),
                'cleaner_fail_rejection' => $this->additionalHistoricalInspection($graph, 'P17-M-LC-F', 'in_progress', $graph['cleaner']),
            ];
            $historicalSnapshots = collect($historical)
                ->map(fn (array $source) => $this->historicalSnapshot($source['inspection']))
                ->all();

            $migration->up();
            $this->assertPackageObjectsExist();
            foreach ($historical as $status => $source) {
                $this->assertSame($historicalSnapshots[$status], $this->historicalSnapshot($source['inspection']));
                $this->assertClaimEvidenceNull($source['inspection']);
            }

            $legacyInProgress = $historical['in_progress'];
            $legacyAdoptionHash = DB::scalar(
                'SELECT hk_p17_inspection_claim_source_hash(?, ?, ?, ?, ?, ?, ?)',
                [1, $graph['property'], $legacyInProgress['inspection'], $legacyInProgress['room'], $legacyInProgress['task'], $graph['cleaner'], $graph['claimant']],
            );
            $this->assertRejectedWithMarker(fn () => DB::table('room_inspections')->where('id', $legacyInProgress['inspection'])->update([
                'claimed_at' => now(),
                'claim_idempotency_key' => 'p17-legacy-adoption',
                'claim_source_hash' => $legacyAdoptionHash,
                'claim_evidence_version' => 1,
            ]), 'historical in-progress adoption', 'HK_P17_INSPECTION_LEGACY_ADOPTION_PROHIBITED');
            $this->assertSame($historicalSnapshots['in_progress'], $this->historicalSnapshot($legacyInProgress['inspection']));
            $this->assertClaimEvidenceNull($legacyInProgress['inspection']);

            $this->assertRejectedWithMarker(fn () => DB::table('room_inspections')->where('id', $legacyInProgress['inspection'])->update([
                'supervisor_id' => $graph['cleaner'],
                'updated_at' => now(),
            ]), 'historical in-progress supervisor takeover', 'HK_P17_INSPECTION_LEGACY_SUPERVISOR_IMMUTABLE');
            $this->assertSame($historicalSnapshots['in_progress'], $this->historicalSnapshot($legacyInProgress['inspection']));
            $this->assertClaimEvidenceNull($legacyInProgress['inspection']);

            DB::table('room_inspections')->where('id', $legacyInProgress['inspection'])->update([
                'status' => 'passed',
                'inspected_at' => now(),
                'remarks' => 'Historical Package 13 compatible non-cleaner terminal evidence',
                'is_passed' => true,
                'updated_at' => now(),
            ]);
            $this->assertSame('passed', DB::table('room_inspections')->where('id', $legacyInProgress['inspection'])->value('status'));
            $this->assertSame($graph['claimant'], DB::table('room_inspections')->where('id', $legacyInProgress['inspection'])->value('supervisor_id'));
            $this->assertClaimEvidenceNull($legacyInProgress['inspection']);
            $historicalSnapshots['in_progress'] = $this->historicalSnapshot($legacyInProgress['inspection']);

            foreach (['cleaner_pass_rejection' => 'passed', 'cleaner_fail_rejection' => 'failed'] as $historicalKey => $terminalStatus) {
                $source = $historical[$historicalKey];
                $this->assertRejectedWithMarker(fn () => DB::table('room_inspections')->where('id', $source['inspection'])->update([
                    'status' => $terminalStatus,
                    'inspected_at' => now(),
                    'remarks' => "Historical cleaner {$terminalStatus} attempt",
                    'is_passed' => $terminalStatus === 'passed',
                    'updated_at' => now(),
                ]), "historical cleaner terminal {$terminalStatus}", 'HK_P17_INSPECTION_LEGACY_TERMINAL_CLEANER_PROHIBITED');
                $this->assertSame($historicalSnapshots[$historicalKey], $this->historicalSnapshot($source['inspection']));
                $this->assertClaimEvidenceNull($source['inspection']);
            }

            foreach (['NC' => $graph['claimant'], 'C' => $graph['cleaner']] as $label => $supervisorId) {
                $source = $this->additionalPendingInspection($graph, 'P17-M-BYPASS-' . $label);
                $this->assertRejectedWithMarker(fn () => DB::table('room_inspections')->where('id', $source['inspection'])->update([
                    'status' => 'in_progress',
                    'supervisor_id' => $supervisorId,
                    'updated_at' => now(),
                ]), "pending no-evidence {$label} claim bypass", 'HK_P17_INSPECTION_CLAIM_BYPASS_PROHIBITED');
                $this->assertSame('pending', DB::table('room_inspections')->where('id', $source['inspection'])->value('status'));
                $this->assertNull(DB::table('room_inspections')->where('id', $source['inspection'])->value('supervisor_id'));
                $this->assertClaimEvidenceNull($source['inspection']);
            }

            $this->assertLegacyStyleInsertRejected($graph, 'in_progress', $graph['claimant'], 'P17-M-RAW-IP');
            $this->assertLegacyStyleInsertRejected($graph, 'passed', null, 'P17-M-RAW-PASS');

            $hash = DB::scalar(
                'SELECT hk_p17_inspection_claim_source_hash(?, ?, ?, ?, ?, ?, ?)',
                [1, $graph['property'], $graph['inspection'], $graph['room'], $graph['task'], $graph['cleaner'], $graph['claimant']],
            );
            DB::table('room_inspections')->where('id', $graph['inspection'])->update([
                'status' => 'in_progress',
                'supervisor_id' => $graph['claimant'],
                'claimed_at' => now(),
                'claim_idempotency_key' => 'p17-migration-valid',
                'claim_source_hash' => $hash,
                'claim_evidence_version' => 1,
                'updated_at' => now(),
            ]);
            $this->assertSame(1, DB::table('room_inspections')->where('id', $graph['inspection'])->value('claim_evidence_version'));

            foreach (['passed', 'failed'] as $invalidInitialStatus) {
                $source = $this->additionalPendingInspection($graph, 'P17-M-INITIAL-' . strtoupper($invalidInitialStatus));
                $invalidHash = DB::scalar(
                    'SELECT hk_p17_inspection_claim_source_hash(?, ?, ?, ?, ?, ?, ?)',
                    [1, $graph['property'], $source['inspection'], $source['room'], $source['task'], $graph['cleaner'], $graph['claimant']],
                );
                $this->assertRejectedWithMarker(fn () => DB::table('room_inspections')->where('id', $source['inspection'])->update([
                    'status' => $invalidInitialStatus,
                    'supervisor_id' => $graph['claimant'],
                    'claimed_at' => now(),
                    'claim_idempotency_key' => 'p17-initial-' . $invalidInitialStatus,
                    'claim_source_hash' => $invalidHash,
                    'claim_evidence_version' => 1,
                ]), "pending to {$invalidInitialStatus} initial claim", 'HK_P17_INSPECTION_CLAIM_INITIAL_STATUS_INVALID');
                $this->assertSame('pending', DB::table('room_inspections')->where('id', $source['inspection'])->value('status'));
                $this->assertClaimEvidenceNull($source['inspection']);
            }

            foreach ([
                'claimant mutation' => fn () => DB::table('room_inspections')->where('id', $graph['inspection'])->update(['supervisor_id' => $graph['cleaner']]),
                'claimed at mutation' => fn () => DB::table('room_inspections')->where('id', $graph['inspection'])->update(['claimed_at' => now()->addMinute()]),
                'claim key mutation' => fn () => DB::table('room_inspections')->where('id', $graph['inspection'])->update(['claim_idempotency_key' => 'p17-migration-changed']),
                'claim hash mutation' => fn () => DB::table('room_inspections')->where('id', $graph['inspection'])->update(['claim_source_hash' => str_repeat('a', 64)]),
                'claim version mutation' => fn () => DB::table('room_inspections')->where('id', $graph['inspection'])->update(['claim_evidence_version' => 2]),
                'source room mutation' => fn () => DB::table('room_inspections')->where('id', $graph['inspection'])->update(['room_id' => $graph['other_room']]),
                'soft delete' => fn () => DB::table('room_inspections')->where('id', $graph['inspection'])->update(['deleted_at' => now()]),
                'physical delete' => fn () => DB::table('room_inspections')->where('id', $graph['inspection'])->delete(),
            ] as $label => $operation) {
                $this->assertRejected($operation, $label);
            }

            $cleanerSource = $this->additionalPendingInspection($graph, 'P17-M-CLEANER');
            $cleanerInspection = $cleanerSource['inspection'];
            $cleanerHash = DB::scalar(
                'SELECT hk_p17_inspection_claim_source_hash(?, ?, ?, ?, ?, ?, ?)',
                [1, $graph['property'], $cleanerInspection, $cleanerSource['room'], $cleanerSource['task'], $graph['cleaner'], $graph['cleaner']],
            );
            $this->assertRejected(fn () => DB::table('room_inspections')->where('id', $cleanerInspection)->update([
                'status' => 'in_progress',
                'supervisor_id' => $graph['cleaner'],
                'claimed_at' => now(),
                'claim_idempotency_key' => 'p17-migration-cleaner',
                'claim_source_hash' => $cleanerHash,
                'claim_evidence_version' => 1,
            ]), 'cleaner claiming own task');

            $duplicateSource = $this->additionalPendingInspection($graph, 'P17-M-DUP');
            $duplicateInspection = $duplicateSource['inspection'];
            $duplicateHash = DB::scalar(
                'SELECT hk_p17_inspection_claim_source_hash(?, ?, ?, ?, ?, ?, ?)',
                [1, $graph['property'], $duplicateInspection, $duplicateSource['room'], $duplicateSource['task'], $graph['cleaner'], $graph['claimant']],
            );
            $this->assertRejected(fn () => DB::table('room_inspections')->where('id', $duplicateInspection)->update([
                'status' => 'in_progress',
                'supervisor_id' => $graph['claimant'],
                'claimed_at' => now(),
                'claim_idempotency_key' => 'p17-migration-valid',
                'claim_source_hash' => $duplicateHash,
                'claim_evidence_version' => 1,
            ]), 'Property-scoped duplicate idempotency key');

            $migration->down();
            $this->assertFalse(Schema::hasColumn('room_inspections', 'claim_source_hash'));
            $this->assertSame(0, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_p17_inspection_claim_guard_trigger'));

            $migration->up();
            $this->assertPackageObjectsExist();
            foreach ($historical as $status => $source) {
                $this->assertSame($historicalSnapshots[$status], $this->historicalSnapshot($source['inspection']));
                $this->assertClaimEvidenceNull($source['inspection']);
            }
            $this->assertSame('in_progress', DB::table('room_inspections')->where('id', $graph['inspection'])->value('status'));
            $this->assertSame($graph['claimant'], DB::table('room_inspections')->where('id', $graph['inspection'])->value('supervisor_id'));
            $this->assertClaimEvidenceNull($graph['inspection']);
        } finally {
            $this->switchDatabase($originalDatabase);
            $this->terminateConnections($admin, $database);
            $admin->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database));
            $this->assertSame(0, $this->databaseCount($admin, $database));
        }
    }

    /** @return array<string, string> */
    private function graph(): array
    {
        $company = Company::create(['name' => 'P17 Migration Company', 'slug' => 'p17-migration-' . Str::lower(Str::random(8)), 'is_active' => true]);
        $property = Property::create(['company_id' => $company->id, 'name' => 'P17 Property', 'slug' => 'p17-property-' . Str::lower(Str::random(8)), 'code' => 'P17' . Str::upper(Str::random(5)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
        $cleaner = $this->user('P17 Cleaner');
        $claimant = $this->user('P17 Claimant');
        foreach ([$cleaner, $claimant] as $user) {
            DB::table('property_user')->insert(['property_id' => $property->id, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $room = (string) Str::ulid();
        $otherRoom = (string) Str::ulid();
        foreach ([[$room, 'P17-M'], [$otherRoom, 'P17-M-X']] as [$id, $number]) {
            DB::table('rooms')->insert(['id' => $id, 'property_id' => $property->id, 'room_number' => $number, 'room_type' => 'deluxe', 'cleanliness_status' => 'clean', 'readiness_state' => 'waiting_inspection', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $task = (string) Str::ulid();
        DB::table('cleaning_tasks')->insert(['id' => $task, 'property_id' => $property->id, 'room_id' => $room, 'task_type' => 'checkout_cleaning', 'status' => 'completed', 'priority' => 'normal', 'completed_at' => now(), 'completed_by' => $cleaner->id, 'created_at' => now(), 'updated_at' => now()]);
        $inspection = $this->pendingInspection(['property' => $property->id, 'room' => $room, 'task' => $task], 'P17-M-PRIMARY');

        return ['property' => $property->id, 'cleaner' => $cleaner->id, 'claimant' => $claimant->id, 'room' => $room, 'other_room' => $otherRoom, 'task' => $task, 'inspection' => $inspection];
    }

    /** @return array{room: string, task: string, inspection: string} */
    private function additionalPendingInspection(array $graph, string $key): array
    {
        $source = $this->additionalInspectionSource($graph, $key);

        return [
            ...$source,
            'inspection' => $this->pendingInspection(['property' => $graph['property'], 'room' => $source['room'], 'task' => $source['task']], $key),
        ];
    }

    /** @return array{room: string, task: string} */
    private function additionalInspectionSource(array $graph, string $key): array
    {
        $room = (string) Str::ulid();
        DB::table('rooms')->insert(['id' => $room, 'property_id' => $graph['property'], 'room_number' => $key, 'room_type' => 'deluxe', 'cleanliness_status' => 'clean', 'readiness_state' => 'waiting_inspection', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $task = (string) Str::ulid();
        DB::table('cleaning_tasks')->insert(['id' => $task, 'property_id' => $graph['property'], 'room_id' => $room, 'task_type' => 'checkout_cleaning', 'status' => 'completed', 'priority' => 'normal', 'completed_at' => now(), 'completed_by' => $graph['cleaner'], 'created_at' => now(), 'updated_at' => now()]);

        return [
            'room' => $room,
            'task' => $task,
        ];
    }

    /** @return array{room: string, task: string, inspection: string} */
    private function additionalHistoricalInspection(array $graph, string $key, string $status, ?string $supervisorId = null): array
    {
        $source = $this->additionalPendingInspection($graph, $key);
        DB::table('room_inspections')->where('id', $source['inspection'])->update([
            'status' => $status,
            'supervisor_id' => $supervisorId ?? $graph['claimant'],
            'inspected_at' => in_array($status, ['passed', 'failed'], true) ? now() : null,
            'remarks' => "Historical Package 13 {$status} evidence",
            'is_passed' => $status === 'passed',
            'updated_at' => now(),
        ]);

        return $source;
    }

    private function assertLegacyStyleInsertRejected(array $graph, string $status, ?string $supervisorId, string $key): void
    {
        $source = $this->additionalInspectionSource($graph, $key);
        $inspectionId = (string) Str::ulid();
        $this->assertRejectedWithMarker(fn () => DB::table('room_inspections')->insert([
            'id' => $inspectionId,
            'property_id' => $graph['property'],
            'room_id' => $source['room'],
            'cleaning_task_id' => $source['task'],
            'supervisor_id' => $supervisorId,
            'inspection_type' => 'post_cleaning',
            'status' => $status,
            'is_passed' => $status === 'passed',
            'inspected_at' => $status === 'passed' ? now() : null,
            'remarks' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]), "legacy-style post-cleaning {$status} insert", 'HK_P17_INSPECTION_LEGACY_STYLE_INSERT_PROHIBITED');
        $this->assertSame(0, DB::table('room_inspections')->where('id', $inspectionId)->count());
    }

    /** @return array<string, mixed> */
    private function historicalSnapshot(string $inspectionId): array
    {
        return (array) DB::table('room_inspections')->where('id', $inspectionId)->first([
            'property_id',
            'room_id',
            'cleaning_task_id',
            'status',
            'supervisor_id',
            'inspected_at',
            'remarks',
            'is_passed',
        ]);
    }

    private function assertClaimEvidenceNull(string $inspectionId): void
    {
        $row = DB::table('room_inspections')->where('id', $inspectionId)->first([
            'claimed_at',
            'claim_idempotency_key',
            'claim_source_hash',
            'claim_evidence_version',
        ]);
        $this->assertNotNull($row);
        foreach ((array) $row as $field => $value) {
            $this->assertNull($value, "Expected {$field} to remain null for {$inspectionId}.");
        }
    }

    private function pendingInspection(array $graph, string $key): string
    {
        $id = (string) Str::ulid();
        DB::table('room_inspections')->insert([
            'id' => $id,
            'property_id' => $graph['property'],
            'room_id' => $graph['room'],
            'cleaning_task_id' => $graph['task'],
            'inspection_type' => 'post_cleaning',
            'status' => 'pending',
            'is_passed' => false,
            'remarks' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function user(string $name): User
    {
        return User::create(['name' => $name, 'email' => Str::slug($name) . '-' . Str::random(5) . '@example.test', 'password' => Hash::make('password'), 'is_active' => true]);
    }

    /** @param callable(): mixed $operation */
    private function assertRejected(callable $operation, string $label): void
    {
        $rejection = null;
        try {
            DB::beginTransaction();
            $operation();
            DB::commit();
        } catch (\Throwable $exception) {
            $rejection = $exception;
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertNotNull($rejection, "Expected raw PostgreSQL rejection: {$label}");
        $this->assertTrue($rejection instanceof QueryException || $rejection instanceof \PDOException, "Unexpected rejection type for {$label}");
    }

    /** @param callable(): mixed $operation */
    private function assertRejectedWithMarker(callable $operation, string $label, string $marker): void
    {
        $rejection = null;
        try {
            DB::beginTransaction();
            $operation();
            DB::commit();
        } catch (\Throwable $exception) {
            $rejection = $exception;
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertNotNull($rejection, "Expected raw PostgreSQL rejection: {$label}");
        $this->assertTrue($rejection instanceof QueryException || $rejection instanceof \PDOException, "Unexpected rejection type for {$label}");
        $this->assertStringContainsString($marker, $rejection->getMessage(), "Missing PostgreSQL marker for {$label}.");
    }

    private function assertPackageObjectsExist(): void
    {
        foreach (['claimed_at', 'claim_idempotency_key', 'claim_source_hash', 'claim_evidence_version'] as $column) {
            $this->assertTrue(Schema::hasColumn('room_inspections', $column));
        }
        $this->assertSame(1, $this->namedObjectCount('pg_indexes', 'indexname', 'hk_p17_inspection_claim_property_key_unique'));
        $this->assertSame(1, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_p17_inspection_claim_guard_trigger'));
        $this->assertSame(1, $this->namedObjectCount('pg_proc', 'proname', 'hk_p17_inspection_claim_source_hash'));
    }

    private function namedObjectCount(string $catalog, string $column, string $name): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) AS aggregate FROM {$catalog} WHERE {$column} = ?", [$name])->aggregate;
    }

    private function switchDatabase(string $database): void
    {
        DB::disconnect('pgsql');
        config(['database.connections.pgsql.database' => $database]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }

    private function adminPdo(): PDO
    {
        $pgsql = config('database.connections.pgsql');

        return new PDO(sprintf('pgsql:host=%s;port=%s;dbname=postgres', $pgsql['host'], $pgsql['port']), $pgsql['username'], $pgsql['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function terminateConnections(PDO $admin, string $database): void
    {
        $statement = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()');
        $statement->execute([$database]);
    }

    private function databaseCount(PDO $admin, string $database): int
    {
        $statement = $admin->prepare('SELECT COUNT(*) FROM pg_database WHERE datname = ?');
        $statement->execute([$database]);

        return (int) $statement->fetchColumn();
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! str_starts_with($identifier, self::PREFIX) || ! preg_match('/^[a-z0-9_]+$/', $identifier)) {
            throw new \RuntimeException('Disposable database name rejected.');
        }

        return '"' . $identifier . '"';
    }
}
