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

    public function test_disposable_postgresql_up_down_reapply_legacy_preflight_and_raw_integrity(): void
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
            $migration->up();
            $this->assertPackageObjectsExist();

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

            DB::table('room_inspections')->where('id', $graph['inspection'])->update(['status' => 'in_progress']);
            try {
                DB::transaction(fn () => $migration->up());
                $this->fail('Expected legacy non-pending preflight rejection.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('PACKAGE_17_LEGACY_CLAIM_EVIDENCE_REVIEW_REQUIRED', $exception->getMessage());
            }
            $this->assertFalse(Schema::hasColumn('room_inspections', 'claim_source_hash'));

            DB::table('room_inspections')->where('id', $graph['inspection'])->update([
                'status' => 'pending',
                'supervisor_id' => null,
            ]);
            $migration->up();
            $this->assertPackageObjectsExist();
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
        $room = (string) Str::ulid();
        DB::table('rooms')->insert(['id' => $room, 'property_id' => $graph['property'], 'room_number' => $key, 'room_type' => 'deluxe', 'cleanliness_status' => 'clean', 'readiness_state' => 'waiting_inspection', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $task = (string) Str::ulid();
        DB::table('cleaning_tasks')->insert(['id' => $task, 'property_id' => $graph['property'], 'room_id' => $room, 'task_type' => 'checkout_cleaning', 'status' => 'completed', 'priority' => 'normal', 'completed_at' => now(), 'completed_by' => $graph['cleaner'], 'created_at' => now(), 'updated_at' => now()]);

        return [
            'room' => $room,
            'task' => $task,
            'inspection' => $this->pendingInspection(['property' => $graph['property'], 'room' => $room, 'task' => $task], $key),
        ];
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
