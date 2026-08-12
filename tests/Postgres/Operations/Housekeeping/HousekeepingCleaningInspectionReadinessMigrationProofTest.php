<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use PDO;
use Tests\PostgresTestCase;

class HousekeepingCleaningInspectionReadinessMigrationProofTest extends PostgresTestCase
{
    private const PREFIX = 'ivorq_testing_hk_p13_migration_';
    private const MIGRATION = 'Modules/Operations/Housekeeping/database/migrations/2026_08_02_000001_integrate_housekeeping_cleaning_inspection_readiness.php';
    private const SUCCESSOR_MIGRATION = 'Modules/Operations/Housekeeping/database/migrations/2026_08_03_000001_control_housekeeping_task_assignments.php';
    private const PACKAGE_17_SUCCESSOR_MIGRATION = 'Modules/Operations/Housekeeping/database/migrations/2026_08_11_000001_control_housekeeping_inspection_claims.php';

    public function test_disposable_postgresql_up_valid_sql_rejection_matrix_down_and_reapply(): void
    {
        $originalDatabase = config('database.connections.pgsql.database');
        $database = self::PREFIX . strtolower((string) Str::ulid());
        $admin = $this->adminPdo();
        $this->assertStringStartsWith(self::PREFIX, $database);
        $this->assertNotSame($originalDatabase, $database);

        $admin->exec('CREATE DATABASE ' . $this->quoteIdentifier($database));

        try {
            $this->switchDatabase($database);
            Artisan::call('migrate', ['--force' => true]);
            $package17 = require base_path(self::PACKAGE_17_SUCCESSOR_MIGRATION);
            $package17->down();
            $this->assertPackageObjectsExist();
            $this->assertPackage17ObjectsAbsent();

            $graph = $this->sourceGraph();
            $this->assertMalformedSourceBindingMatrix($graph);
            $this->insertValidReworkTaskRaw($graph);
            $this->updateValidVerificationRaw($graph);
            $this->assertSame(1, DB::table('cleaning_tasks')->where('rework_source_inspection_id', $graph['failed_inspection'])->count());
            $this->assertNotNull(DB::table('cleaning_tasks')->where('id', $graph['passed_task'])->value('verified_at'));

            $this->assertMalformedRawSqlMatrix($graph);

            $migration = require base_path(self::MIGRATION);
            $successor = require base_path(self::SUCCESSOR_MIGRATION);
            $successor->down();
            $migration->down();
            $this->assertFalse(Schema::hasColumn('cleaning_tasks', 'rework_source_inspection_id'));
            $this->assertSame(0, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_room_inspections_lifecycle_guard_trigger'));

            $migration->up();
            $successor->up();
            $this->assertPackageObjectsExist();

            $reapply = $this->sourceGraph('R');
            $this->insertValidReworkTaskRaw($reapply);
            $this->assertSame(1, DB::table('cleaning_tasks')->where('rework_source_inspection_id', $reapply['failed_inspection'])->count());

            $package17->up();
            $this->assertPackage17ObjectsExist();
            $this->assertPackage17HistoricalEvidenceNull($graph);
            $this->assertPackage17HistoricalEvidenceNull($reapply);
            $this->assertSame(1, DB::table('cleaning_tasks')->where('rework_source_inspection_id', $reapply['failed_inspection'])->count());
        } finally {
            $this->switchDatabase($originalDatabase);
            $this->terminateConnections($admin, $database);
            $admin->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database));
            $this->assertSame(0, $this->databaseCount($admin, $database));
        }
    }

    /** @return array<string, string> */
    private function sourceGraph(string $suffix = ''): array
    {
        $company = Company::create([
            'name' => 'P13 Migration Company ' . $suffix,
            'slug' => 'p13-migration-' . strtolower(Str::random(8)),
            'is_active' => true,
        ]);
        $property = Property::create([
            'company_id' => $company->id,
            'name' => 'P13 Property ' . $suffix,
            'slug' => 'p13-property-' . strtolower(Str::random(8)),
            'code' => 'P13' . strtoupper(Str::random(5)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $otherProperty = Property::create([
            'company_id' => $company->id,
            'name' => 'P13 Other Property ' . $suffix,
            'slug' => 'p13-other-' . strtolower(Str::random(8)),
            'code' => 'O13' . strtoupper(Str::random(5)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $actor = User::create([
            'name' => 'P13 Migration Actor',
            'email' => 'p13-' . strtolower(Str::random(8)) . '@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $department = Department::create([
            'property_id' => $property->id,
            'name' => 'P13 Migration Housekeeping',
            'code' => 'P13' . Str::upper(Str::random(5)),
            'is_active' => true,
        ]);
        $actor->update(['department_id' => $department->id]);
        DB::table('property_user')->insert([
            'property_id' => $property->id,
            'user_id' => $actor->id,
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $room = (string) Str::ulid();
        $samePropertyOtherRoom = (string) Str::ulid();
        $otherRoom = (string) Str::ulid();
        DB::table('rooms')->insert([
            ['id' => $room, 'property_id' => $property->id, 'room_number' => 'M13' . Str::random(3), 'room_type' => 'standard', 'cleanliness_status' => 'clean', 'readiness_state' => 'waiting_inspection', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $samePropertyOtherRoom, 'property_id' => $property->id, 'room_number' => 'S13' . Str::random(3), 'room_type' => 'standard', 'cleanliness_status' => 'dirty', 'readiness_state' => 'waiting_cleaning', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $otherRoom, 'property_id' => $otherProperty->id, 'room_number' => 'X13' . Str::random(3), 'room_type' => 'standard', 'cleanliness_status' => 'dirty', 'readiness_state' => 'waiting_cleaning', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $failedTask = (string) Str::ulid();
        $passedTask = (string) Str::ulid();
        DB::table('cleaning_tasks')->insert([
            ['id' => $failedTask, 'property_id' => $property->id, 'room_id' => $room, 'task_type' => 'checkout_cleaning', 'status' => 'completed', 'priority' => 'normal', 'credits' => 1, 'completed_at' => now(), 'completed_by' => $actor->id, 'notes' => 'Completed source', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $passedTask, 'property_id' => $property->id, 'room_id' => $room, 'task_type' => 'checkout_cleaning', 'status' => 'completed', 'priority' => 'normal', 'credits' => 1, 'completed_at' => now(), 'completed_by' => $actor->id, 'notes' => 'Passed source', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $failedInspection = (string) Str::ulid();
        $passedInspection = (string) Str::ulid();
        DB::table('room_inspections')->insert([
            ['id' => $failedInspection, 'property_id' => $property->id, 'room_id' => $room, 'cleaning_task_id' => $failedTask, 'inspection_type' => 'post_cleaning', 'status' => 'failed', 'is_passed' => false, 'supervisor_id' => $actor->id, 'inspected_at' => now(), 'remarks' => 'Failed source', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $passedInspection, 'property_id' => $property->id, 'room_id' => $room, 'cleaning_task_id' => $passedTask, 'inspection_type' => 'post_cleaning', 'status' => 'passed', 'is_passed' => true, 'supervisor_id' => $actor->id, 'inspected_at' => now(), 'remarks' => 'Passed source', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [
            'property' => $property->id,
            'other_property' => $otherProperty->id,
            'room' => $room,
            'same_property_other_room' => $samePropertyOtherRoom,
            'other_room' => $otherRoom,
            'actor' => $actor->id,
            'department' => $department->id,
            'failed_task' => $failedTask,
            'passed_task' => $passedTask,
            'failed_inspection' => $failedInspection,
            'passed_inspection' => $passedInspection,
        ];
    }

    /** @param array<string, string> $graph */
    private function insertValidReworkTaskRaw(array $graph): void
    {
        DB::insert(<<<'SQL'
            INSERT INTO cleaning_tasks
                (id, property_id, room_id, task_code, title, task_type, status, priority, credits, rework_source_inspection_id, source_cleaning_task_id, created_by, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, 'checkout_cleaning', 'pending', 'normal', 1, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        SQL, [
            (string) Str::ulid(),
            $graph['property'],
            $graph['room'],
            'REWORK-' . Str::random(6),
            'Valid raw re-cleaning task',
            $graph['failed_inspection'],
            $graph['failed_task'],
            $graph['actor'],
        ]);
    }

    /** @param array<string, string> $graph */
    private function updateValidVerificationRaw(array $graph): void
    {
        DB::update('UPDATE cleaning_tasks SET verified_at = CURRENT_TIMESTAMP WHERE id = ?', [$graph['passed_task']]);
    }

    /** @param array<string, string> $graph */
    private function assertMalformedRawSqlMatrix(array $graph): void
    {
        $cases = [
            'duplicate post-cleaning Inspection' => fn () => DB::insert(
                "INSERT INTO room_inspections (id, property_id, room_id, cleaning_task_id, inspection_type, status, is_passed, created_at, updated_at) VALUES (?, ?, ?, ?, 'post_cleaning', 'pending', false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [(string) Str::ulid(), $graph['property'], $graph['room'], $graph['failed_task']]
            ),
            'duplicate rework source' => fn () => $this->insertValidReworkTaskRaw($graph),
            'cross-property Cleaning Task Room' => fn () => DB::insert(
                "INSERT INTO cleaning_tasks (id, property_id, room_id, task_type, status, priority, credits, created_at, updated_at) VALUES (?, ?, ?, 'checkout_cleaning', 'pending', 'normal', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [(string) Str::ulid(), $graph['property'], $graph['other_room']]
            ),
            'cross-property Inspection Task' => fn () => DB::insert(
                "INSERT INTO room_inspections (id, property_id, room_id, cleaning_task_id, inspection_type, status, is_passed, created_at, updated_at) VALUES (?, ?, ?, ?, 'routine', 'pending', false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [(string) Str::ulid(), $graph['other_property'], $graph['other_room'], $graph['failed_task']]
            ),
            'rework without source task' => fn () => DB::insert(
                "INSERT INTO cleaning_tasks (id, property_id, room_id, task_type, status, priority, credits, rework_source_inspection_id, created_at, updated_at) VALUES (?, ?, ?, 'checkout_cleaning', 'pending', 'normal', 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [(string) Str::ulid(), $graph['property'], $graph['room'], $graph['passed_inspection']]
            ),
            'invalid readiness transition type' => fn () => DB::insert(
                "INSERT INTO housekeeping_room_readiness_transitions (id, property_id, room_id, from_status, to_status, transition_type, occurred_at, created_by, idempotency_key, source_hash, created_at) VALUES (?, ?, ?, 'waiting_inspection', 'ready_for_sale', 'BYPASS_READY', CURRENT_TIMESTAMP, ?, ?, ?, CURRENT_TIMESTAMP)",
                [(string) Str::ulid(), $graph['property'], $graph['room'], $graph['actor'], 'bad-' . Str::random(5), str_repeat('a', 64)]
            ),
            'terminal Inspection update' => fn () => DB::update("UPDATE room_inspections SET remarks = 'overwrite' WHERE id = ?", [$graph['failed_inspection']]),
            'terminal Inspection delete' => fn () => DB::delete('DELETE FROM room_inspections WHERE id = ?', [$graph['failed_inspection']]),
            'completed Task rewrite' => fn () => DB::update("UPDATE cleaning_tasks SET notes = 'overwrite' WHERE id = ?", [$graph['failed_task']]),
            'completed Task delete' => fn () => DB::delete('DELETE FROM cleaning_tasks WHERE id = ?', [$graph['failed_task']]),
        ];

        foreach ($cases as $label => $operation) {
            try {
                $operation();
                $this->fail("Malformed raw SQL case was accepted: {$label}");
            } catch (QueryException $exception) {
                $this->assertNotSame('', $exception->getMessage(), $label);
            }
        }
    }

    /** @param array<string, string> $graph */
    private function assertMalformedSourceBindingMatrix(array $graph): void
    {
        $mismatched = $this->insertSourcePair($graph, 'failed');
        $differentTask = $this->insertSourcePair($graph, 'failed');
        $passed = $this->insertSourcePair($graph, 'passed');
        $pending = $this->insertSourcePair($graph, 'pending');
        $inProgress = $this->insertSourcePair($graph, 'in_progress');
        $routine = $this->insertSourcePair($graph, 'failed', 'routine');
        $wrongRoom = $this->insertSourcePair($graph, 'failed', 'post_cleaning', 'completed', 'checkout_cleaning', 'same_property_other_room');
        $wrongProperty = $this->insertSourcePair($graph, 'failed', 'post_cleaning', 'completed', 'checkout_cleaning', 'other_room');
        $incompleteTask = $this->insertSourcePair($graph, 'failed', 'post_cleaning', 'in_progress');
        $nonCheckoutTask = $this->insertSourcePair($graph, 'failed', 'post_cleaning', 'completed', 'turndown');

        $cases = [
            'missing source task' => fn () => $this->insertReworkRaw($graph, $passed['inspection'], null),
            'mismatched source task' => fn () => $this->insertReworkRaw($graph, $mismatched['inspection'], $differentTask['task']),
            'passed Inspection source' => fn () => $this->insertReworkRaw($graph, $passed['inspection'], $passed['task']),
            'pending Inspection source' => fn () => $this->insertReworkRaw($graph, $pending['inspection'], $pending['task']),
            'in-progress Inspection source' => fn () => $this->insertReworkRaw($graph, $inProgress['inspection'], $inProgress['task']),
            'routine Inspection source' => fn () => $this->insertReworkRaw($graph, $routine['inspection'], $routine['task']),
            'wrong Room source' => fn () => $this->insertReworkRaw($graph, $wrongRoom['inspection'], $wrongRoom['task']),
            'wrong Property source' => fn () => $this->insertReworkRaw($graph, $wrongProperty['inspection'], $wrongProperty['task']),
            'source Task not completed' => fn () => $this->insertReworkRaw($graph, $incompleteTask['inspection'], $incompleteTask['task']),
            'source Task not checkout cleaning' => fn () => $this->insertReworkRaw($graph, $nonCheckoutTask['inspection'], $nonCheckoutTask['task']),
            'initial rework status not pending' => fn () => $this->insertReworkRaw($graph, $mismatched['inspection'], $mismatched['task'], 'assigned'),
            'self-referencing source Task' => function () use ($graph): void {
                $id = (string) Str::ulid();
                DB::insert(
                    "INSERT INTO cleaning_tasks (id, property_id, room_id, task_type, status, priority, credits, source_cleaning_task_id, created_at, updated_at) VALUES (?, ?, ?, 'checkout_cleaning', 'pending', 'normal', 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                    [$id, $graph['property'], $graph['room'], $id],
                );
            },
        ];

        foreach ($cases as $label => $operation) {
            try {
                $operation();
                $this->fail("Malformed source-binding case was accepted: {$label}");
            } catch (QueryException $exception) {
                $this->assertNotSame('', $exception->getMessage(), $label);
            }
        }
    }

    /**
     * @param array<string, string> $graph
     * @return array{task: string, inspection: string}
     */
    private function insertSourcePair(
        array $graph,
        string $inspectionStatus,
        string $inspectionType = 'post_cleaning',
        string $taskStatus = 'completed',
        string $taskType = 'checkout_cleaning',
        string $roomKey = 'room',
    ): array {
        $task = (string) Str::ulid();
        $inspection = (string) Str::ulid();
        $property = $roomKey === 'other_room' ? $graph['other_property'] : $graph['property'];
        $room = $graph[$roomKey];

        DB::transaction(function () use ($graph, $task, $inspection, $property, $room, $taskType, $taskStatus, $inspectionType, $inspectionStatus): void {
            DB::table('cleaning_tasks')->insert([
                'id' => $task, 'property_id' => $property, 'room_id' => $room, 'task_type' => $taskType,
                'status' => $taskStatus, 'priority' => 'normal', 'credits' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (in_array($taskStatus, ['assigned', 'in_progress'], true)) {
                DB::table('housekeeping_task_assignments')->insert([
                    'id' => (string) Str::ulid(),
                    'property_id' => $property,
                    'cleaning_task_id' => $task,
                    'user_id' => $graph['actor'],
                    'attendant_id' => $graph['actor'],
                    'department_id' => $graph['department'],
                    'status' => 'active',
                    'assigned_at' => now(),
                    'assigned_by' => $graph['actor'],
                    'assignment_action' => 'initial',
                    'idempotency_key' => 'p13-migration-' . Str::uuid(),
                    'source_hash' => hash('sha256', 'p13-migration-' . $task),
                    'evidence_version' => 'housekeeping-assignment-v1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('room_inspections')->insert([
                'id' => $inspection, 'property_id' => $property, 'room_id' => $room, 'cleaning_task_id' => $task,
                'inspection_type' => $inspectionType, 'status' => $inspectionStatus,
                'is_passed' => $inspectionStatus === 'passed', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return ['task' => $task, 'inspection' => $inspection];
    }

    /** @param array<string, string> $graph */
    private function insertReworkRaw(
        array $graph,
        string $inspectionId,
        ?string $sourceTaskId,
        string $status = 'pending',
    ): void {
        DB::insert(
            'INSERT INTO cleaning_tasks (id, property_id, room_id, task_type, status, priority, credits, rework_source_inspection_id, source_cleaning_task_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [(string) Str::ulid(), $graph['property'], $graph['room'], 'checkout_cleaning', $status, 'normal', 1, $inspectionId, $sourceTaskId, $graph['actor']],
        );
    }

    private function assertPackageObjectsExist(): void
    {
        $this->assertTrue(Schema::hasColumn('cleaning_tasks', 'rework_source_inspection_id'));
        $this->assertTrue(Schema::hasColumn('cleaning_tasks', 'source_cleaning_task_id'));
        $this->assertSame(1, $this->namedObjectCount('pg_indexes', 'indexname', 'hk_room_inspections_post_cleaning_task_unique'));
        $this->assertSame(1, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_room_inspections_lifecycle_guard_trigger'));
        $this->assertSame(1, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_cleaning_tasks_lifecycle_guard_trigger'));
        $this->assertSame(1, $this->namedObjectCount('pg_constraint', 'conname', 'hk_cleaning_tasks_source_not_self_check'));
    }

    private function assertPackage17ObjectsAbsent(): void
    {
        $this->assertFalse(Schema::hasColumn('room_inspections', 'claim_evidence_version'));
        $this->assertSame(0, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_p17_inspection_claim_guard_trigger'));
    }

    private function assertPackage17ObjectsExist(): void
    {
        foreach (['claimed_at', 'claim_idempotency_key', 'claim_source_hash', 'claim_evidence_version'] as $column) {
            $this->assertTrue(Schema::hasColumn('room_inspections', $column));
        }
        $this->assertSame(1, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_p17_inspection_claim_guard_trigger'));
    }

    /** @param array<string, string> $graph */
    private function assertPackage17HistoricalEvidenceNull(array $graph): void
    {
        foreach ([
            $graph['failed_inspection'] => ['failed', $graph['actor']],
            $graph['passed_inspection'] => ['passed', $graph['actor']],
        ] as $inspectionId => [$status, $supervisorId]) {
            $row = DB::table('room_inspections')->where('id', $inspectionId)->first([
                'status',
                'supervisor_id',
                'claimed_at',
                'claim_idempotency_key',
                'claim_source_hash',
                'claim_evidence_version',
            ]);
            $this->assertNotNull($row);
            $this->assertSame($status, $row->status);
            $this->assertSame($supervisorId, $row->supervisor_id);
            $this->assertNull($row->claimed_at);
            $this->assertNull($row->claim_idempotency_key);
            $this->assertNull($row->claim_source_hash);
            $this->assertNull($row->claim_evidence_version);
        }
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

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', $pgsql['host'], $pgsql['port']),
            $pgsql['username'],
            $pgsql['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
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
