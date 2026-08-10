<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use PDO;
use Tests\PostgresTestCase;

class HousekeepingControlledDispatchAssignmentMigrationProofTest extends PostgresTestCase
{
    private const PREFIX = 'ivorq_testing_hk_p15_migration_';
    private const MIGRATION = 'Modules/Operations/Housekeeping/database/migrations/2026_08_03_000001_control_housekeeping_task_assignments.php';

    public function test_disposable_postgresql_legacy_normalization_raw_integrity_down_and_reapply(): void
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
            $legacyTask = $this->task($graph['property'], $graph['room'], 'assigned');
            $legacyAssignment = (string) Str::ulid();
            DB::table('housekeeping_task_assignments')->insert([
                'id' => $legacyAssignment,
                'property_id' => null,
                'cleaning_task_id' => $legacyTask,
                'user_id' => null,
                'attendant_id' => $graph['target_one'],
                'department_id' => $graph['department'],
                'status' => 'active',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration->up();
            $this->assertPackageObjectsExist();
            $legacy = DB::table('housekeeping_task_assignments')->where('id', $legacyAssignment)->first();
            $this->assertSame($graph['property'], $legacy->property_id);
            $this->assertSame($graph['target_one'], $legacy->user_id);
            $this->assertSame($legacy->user_id, $legacy->attendant_id);
            $this->assertSame('housekeeping-assignment-legacy-v0', $legacy->evidence_version);

            $task = $this->task($graph['property'], $graph['room'], 'pending');
            $initial = (string) Str::ulid();
            DB::transaction(function () use ($graph, $task, $initial): void {
                DB::table('cleaning_tasks')->where('id', $task)->update(['status' => 'assigned']);
                $this->insertCanonical($graph, $task, $initial, [
                    'idempotency_key' => 'valid-initial',
                    'source_hash' => hash('sha256', 'valid-initial'),
                ]);
            });
            $replacement = (string) Str::ulid();
            DB::transaction(function () use ($graph, $task, $initial, $replacement): void {
                DB::table('housekeeping_task_assignments')->where('id', $initial)->update([
                    'status' => 'cancelled',
                    'closed_at' => now(),
                    'closed_by' => $graph['actor'],
                    'closure_reason' => 'reassigned',
                    'updated_at' => now(),
                ]);
                $this->insertCanonical($graph, $task, $replacement, [
                    'user_id' => $graph['target_two'],
                    'attendant_id' => $graph['target_two'],
                    'assignment_action' => 'reassignment',
                    'previous_assignment_id' => $initial,
                    'idempotency_key' => 'valid-reassignment',
                    'source_hash' => hash('sha256', 'valid-reassignment'),
                ]);
            });
            $this->assertSame('cancelled', DB::table('housekeeping_task_assignments')->where('id', $initial)->value('status'));
            $this->assertSame('reassigned', DB::table('housekeeping_task_assignments')->where('id', $initial)->value('closure_reason'));
            $this->assertSame($initial, DB::table('housekeeping_task_assignments')->where('id', $replacement)->value('previous_assignment_id'));

            $this->assertRawRejectionMatrix($graph, $task, $replacement);

            $migration->down();
            $this->assertFalse(Schema::hasColumn('housekeeping_task_assignments', 'source_hash'));
            $this->assertSame(0, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_p15_assignment_immutability_guard_trigger'));

            $duplicate = (string) Str::ulid();
            DB::table('housekeeping_task_assignments')->insert([
                'id' => $duplicate,
                'property_id' => $graph['property'],
                'cleaning_task_id' => $task,
                'user_id' => $graph['target_one'],
                'attendant_id' => $graph['target_one'],
                'department_id' => $graph['department'],
                'status' => 'active',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertMigrationConflict($migration);
            DB::table('housekeeping_task_assignments')->where('id', $duplicate)->delete();

            DB::table('housekeeping_task_assignments')->where('id', $replacement)->update(['property_id' => $graph['other_property']]);
            $this->assertMigrationConflict($migration);
            DB::table('housekeeping_task_assignments')->where('id', $replacement)->update(['property_id' => $graph['property']]);

            $pendingConflictTask = $this->task($graph['property'], $graph['room'], 'pending');
            $pendingConflictAssignment = (string) Str::ulid();
            DB::table('housekeeping_task_assignments')->insert([
                'id' => $pendingConflictAssignment,
                'property_id' => $graph['property'],
                'cleaning_task_id' => $pendingConflictTask,
                'user_id' => $graph['target_one'],
                'attendant_id' => $graph['target_one'],
                'department_id' => $graph['department'],
                'status' => 'active',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertMigrationConflict($migration);
            DB::table('housekeeping_task_assignments')->where('id', $pendingConflictAssignment)->delete();

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
        $company = Company::create(['name' => 'P15 Migration Company', 'slug' => 'p15-migration-' . strtolower(Str::random(8)), 'is_active' => true]);
        $property = Property::create(['company_id' => $company->id, 'name' => 'P15 Property', 'slug' => 'p15-property-' . strtolower(Str::random(8)), 'code' => 'P15' . strtoupper(Str::random(5)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
        $other = Property::create(['company_id' => $company->id, 'name' => 'P15 Other', 'slug' => 'p15-other-' . strtolower(Str::random(8)), 'code' => 'P1X' . strtoupper(Str::random(5)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
        $department = Department::create(['property_id' => $property->id, 'name' => 'Housekeeping', 'code' => 'HK' . strtoupper(Str::random(5)), 'is_active' => true]);
        $alternateDepartment = Department::create(['property_id' => $property->id, 'name' => 'Alternate', 'code' => 'ALT' . strtoupper(Str::random(4)), 'is_active' => true]);
        $inactiveDepartment = Department::create(['property_id' => $property->id, 'name' => 'Inactive', 'code' => 'INA' . strtoupper(Str::random(4)), 'is_active' => false]);
        $deletedDepartment = Department::create(['property_id' => $property->id, 'name' => 'Deleted', 'code' => 'DEL' . strtoupper(Str::random(4)), 'is_active' => true]);
        DB::table('departments')->where('id', $deletedDepartment->id)->update(['deleted_at' => now()]);
        $otherDepartment = Department::create(['property_id' => $other->id, 'name' => 'Other', 'code' => 'OHK' . strtoupper(Str::random(4)), 'is_active' => true]);
        $actor = $this->user('P15 Actor', $department->id);
        $targetOne = $this->user('P15 Target One', $department->id);
        $targetTwo = $this->user('P15 Target Two', $department->id);
        foreach ([$actor, $targetOne, $targetTwo] as $user) {
            DB::table('property_user')->insert(['property_id' => $property->id, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $room = (string) Str::ulid();
        DB::table('rooms')->insert(['id' => $room, 'property_id' => $property->id, 'room_number' => 'P15-M', 'room_type' => 'deluxe', 'cleanliness_status' => 'dirty', 'readiness_state' => 'waiting_cleaning', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $otherRoom = (string) Str::ulid();
        DB::table('rooms')->insert(['id' => $otherRoom, 'property_id' => $other->id, 'room_number' => 'P15-X', 'room_type' => 'deluxe', 'cleanliness_status' => 'dirty', 'readiness_state' => 'waiting_cleaning', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return [
            'property' => $property->id,
            'other_property' => $other->id,
            'department' => $department->id,
            'alternate_department' => $alternateDepartment->id,
            'inactive_department' => $inactiveDepartment->id,
            'deleted_department' => $deletedDepartment->id,
            'other_department' => $otherDepartment->id,
            'actor' => $actor->id,
            'target_one' => $targetOne->id,
            'target_two' => $targetTwo->id,
            'room' => $room,
            'other_room' => $otherRoom,
        ];
    }

    private function user(string $name, string $departmentId): User
    {
        return User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '-' . Str::random(5) . '@example.test', 'password' => Hash::make('password'), 'department_id' => $departmentId, 'is_active' => true]);
    }

    private function task(string $propertyId, string $roomId, string $status): string
    {
        $id = (string) Str::ulid();
        DB::table('cleaning_tasks')->insert(['id' => $id, 'property_id' => $propertyId, 'room_id' => $roomId, 'task_type' => 'checkout_cleaning', 'status' => $status, 'priority' => 'normal', 'credits' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    private function insertCanonical(array $graph, string $task, string $id, array $overrides = []): void
    {
        DB::table('housekeeping_task_assignments')->insert(array_merge([
            'id' => $id,
            'property_id' => $graph['property'],
            'cleaning_task_id' => $task,
            'user_id' => $graph['target_one'],
            'attendant_id' => $graph['target_one'],
            'department_id' => $graph['department'],
            'status' => 'active',
            'assigned_at' => now(),
            'assigned_by' => $graph['actor'],
            'assignment_action' => 'initial',
            'idempotency_key' => 'raw-' . Str::uuid(),
            'source_hash' => hash('sha256', (string) Str::uuid()),
            'evidence_version' => 'housekeeping-assignment-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function assertRawRejectionMatrix(array $graph, string $activeTask, string $activeAssignment): void
    {
        $invalidTarget = $this->user('P15 Inactive Target', $graph['department']);
        $invalidTarget->update(['is_active' => false]);
        $deletedTarget = $this->user('P15 Deleted Target', $graph['department']);
        $deletedTarget->delete();
        $noMembership = $this->user('P15 No Membership', $graph['department']);
        $inactiveMembership = $this->user('P15 Inactive Membership', $graph['department']);
        DB::table('property_user')->insert(['property_id' => $graph['property'], 'user_id' => $inactiveMembership->id, 'status' => 'inactive', 'is_default' => false, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $departmentMismatch = $this->user('P15 Department Mismatch', $graph['alternate_department']);
        DB::table('property_user')->insert(['property_id' => $graph['property'], 'user_id' => $departmentMismatch->id, 'status' => 'active', 'is_default' => false, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $cases = [];
        $cases['conflicting user mirror'] = ['user_id' => $graph['target_one'], 'attendant_id' => $graph['target_two']];
        $cases['inactive user'] = ['user_id' => $invalidTarget->id, 'attendant_id' => $invalidTarget->id];
        $cases['soft deleted user'] = ['user_id' => $deletedTarget->id, 'attendant_id' => $deletedTarget->id];
        $cases['missing membership'] = ['user_id' => $noMembership->id, 'attendant_id' => $noMembership->id];
        $cases['inactive membership'] = ['user_id' => $inactiveMembership->id, 'attendant_id' => $inactiveMembership->id];
        $cases['wrong property department'] = ['department_id' => $graph['other_department']];
        $cases['inactive department'] = ['department_id' => $graph['inactive_department']];
        $cases['deleted department'] = ['department_id' => $graph['deleted_department']];
        $cases['user department mismatch'] = ['user_id' => $departmentMismatch->id, 'attendant_id' => $departmentMismatch->id];
        $cases['missing assigned by'] = ['assigned_by' => null];
        $cases['assigned by without property authority'] = ['assigned_by' => $noMembership->id];
        $cases['missing key'] = ['idempotency_key' => null];
        $cases['missing hash'] = ['source_hash' => null];
        $cases['invalid status'] = ['status' => 'accepted'];
        $cases['reassignment missing previous'] = ['assignment_action' => 'reassignment', 'previous_assignment_id' => null];
        $cases['self previous'] = ['assignment_action' => 'reassignment', 'previous_assignment_id' => '__SELF__'];

        foreach ($cases as $label => $overrides) {
            $task = $this->task($graph['property'], $graph['room'], 'pending');
            $id = (string) Str::ulid();
            if (($overrides['previous_assignment_id'] ?? null) === '__SELF__') {
                $overrides['previous_assignment_id'] = $id;
            }
            $this->assertRejected(function () use ($graph, $task, $id, $overrides): void {
                DB::table('cleaning_tasks')->where('id', $task)->update(['status' => 'assigned']);
                $this->insertCanonical($graph, $task, $id, $overrides);
            }, $label);
        }

        $crossPropertyTask = $this->task($graph['other_property'], $graph['other_room'], 'pending');
        $this->assertRejected(function () use ($graph, $crossPropertyTask): void {
            DB::table('cleaning_tasks')->where('id', $crossPropertyTask)->update(['status' => 'assigned']);
            $this->insertCanonical($graph, $crossPropertyTask, (string) Str::ulid());
        }, 'assignment task belongs to another property');

        $missingRoomTask = (string) Str::ulid();
        DB::table('cleaning_tasks')->insert([
            'id' => $missingRoomTask,
            'property_id' => $graph['property'],
            'room_id' => null,
            'task_type' => 'checkout_cleaning',
            'status' => 'pending',
            'priority' => 'normal',
            'credits' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertRejected(function () use ($graph, $missingRoomTask): void {
            DB::table('cleaning_tasks')->where('id', $missingRoomTask)->update(['status' => 'assigned']);
            $this->insertCanonical($graph, $missingRoomTask, (string) Str::ulid());
        }, 'assignment task room relationship is missing');

        $inactivePropertyTask = $this->task($graph['property'], $graph['room'], 'pending');
        $this->assertRejected(function () use ($graph, $inactivePropertyTask): void {
            DB::table('properties')->where('id', $graph['property'])->update(['is_active' => false]);
            DB::table('cleaning_tasks')->where('id', $inactivePropertyTask)->update(['status' => 'assigned']);
            $this->insertCanonical($graph, $inactivePropertyTask, (string) Str::ulid());
        }, 'inactive assignment property');

        $duplicateKeyTask = $this->task($graph['property'], $graph['room'], 'pending');
        $this->assertRejected(function () use ($graph, $duplicateKeyTask): void {
            DB::table('cleaning_tasks')->where('id', $duplicateKeyTask)->update(['status' => 'assigned']);
            $this->insertCanonical($graph, $duplicateKeyTask, (string) Str::ulid(), ['idempotency_key' => 'valid-reassignment']);
        }, 'duplicate idempotency');

        $pendingTask = $this->task($graph['property'], $graph['room'], 'pending');
        $this->assertRejected(fn () => $this->insertCanonical($graph, $pendingTask, (string) Str::ulid()), 'active assignment for pending task');
        $this->assertRejected(fn () => $this->insertCanonical($graph, $activeTask, (string) Str::ulid()), 'duplicate active assignment for task');
        $assignedZero = $this->task($graph['property'], $graph['room'], 'pending');
        $this->assertRejected(fn () => DB::table('cleaning_tasks')->where('id', $assignedZero)->update(['status' => 'assigned']), 'assigned task without assignment');

        $otherTask = $this->task($graph['property'], $graph['room'], 'pending');
        $otherTaskPrevious = $this->insertCanonicalPrevious($graph, $otherTask);
        $samePropertyTask = $this->task($graph['property'], $graph['room'], 'pending');
        $this->assertRejected(function () use ($graph, $samePropertyTask, $otherTaskPrevious): void {
            DB::table('cleaning_tasks')->where('id', $samePropertyTask)->update(['status' => 'assigned']);
            $this->insertCanonical($graph, $samePropertyTask, (string) Str::ulid(), [
                'assignment_action' => 'reassignment',
                'previous_assignment_id' => $otherTaskPrevious,
            ]);
        }, 'previous assignment from another task');

        $activePreviousTask = $this->task($graph['property'], $graph['room'], 'pending');
        $activePrevious = (string) Str::ulid();
        DB::transaction(function () use ($graph, $activePreviousTask, $activePrevious): void {
            DB::table('cleaning_tasks')->where('id', $activePreviousTask)->update(['status' => 'assigned']);
            $this->insertCanonical($graph, $activePreviousTask, $activePrevious);
        });
        $this->assertRejected(fn () => $this->insertCanonical($graph, $activePreviousTask, (string) Str::ulid(), [
            'assignment_action' => 'reassignment',
            'previous_assignment_id' => $activePrevious,
        ]), 'previous assignment is not terminal reassigned');

        foreach (['completed', 'cancelled'] as $terminal) {
            $this->assertRejected(fn () => DB::table('cleaning_tasks')->where('id', $activeTask)->update(['status' => $terminal]), "{$terminal} task with active assignment");
        }
        $this->assertRejected(fn () => DB::table('housekeeping_task_assignments')->where('id', $activeAssignment)->update(['status' => 'cancelled']), 'invalid closure evidence');
        $this->assertRejected(fn () => DB::table('housekeeping_task_assignments')->where('id', $activeAssignment)->update(['user_id' => $graph['target_one']]), 'immutable identity');
        $this->assertRejected(fn () => DB::table('housekeeping_task_assignments')->where('id', $activeAssignment)->delete(), 'physical delete');
        $this->assertRejected(fn () => DB::table('housekeeping_task_assignments')->where('id', $activeAssignment)->update(['deleted_at' => now()]), 'soft delete');

        $terminalId = DB::table('housekeeping_task_assignments')
            ->where('cleaning_task_id', $activeTask)
            ->where('status', 'cancelled')
            ->where('closure_reason', 'reassigned')
            ->value('id');
        $this->assertRejected(fn () => DB::table('housekeeping_task_assignments')->where('id', $terminalId)->update(['status' => 'active']), 'terminal returning active');
        $this->assertRejected(fn () => DB::table('housekeeping_task_assignments')->where('id', $terminalId)->update(['closure_reason' => 'changed']), 'terminal closure mutation');
    }

    private function insertCanonicalPrevious(array $graph, string $taskId): string
    {
        $id = (string) Str::ulid();
        DB::transaction(function () use ($graph, $taskId, $id): void {
            DB::table('cleaning_tasks')->where('id', $taskId)->update(['status' => 'assigned']);
            $this->insertCanonical($graph, $taskId, $id);
            DB::table('housekeeping_task_assignments')->where('id', $id)->update([
                'status' => 'cancelled',
                'closed_at' => now(),
                'closed_by' => $graph['actor'],
                'closure_reason' => 'reassigned',
                'updated_at' => now(),
            ]);
            DB::table('cleaning_tasks')->where('id', $taskId)->update(['status' => 'pending']);
        });

        return $id;
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

    private function assertMigrationConflict(object $migration): void
    {
        try {
            DB::beginTransaction();
            $migration->up();
            DB::commit();
            $this->fail('Expected HK_P15_SOURCE_CONFLICT migration rejection.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('HK_P15_SOURCE_CONFLICT', $exception->getMessage());
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function assertPackageObjectsExist(): void
    {
        $this->assertTrue(Schema::hasColumn('housekeeping_task_assignments', 'source_hash'));
        $this->assertSame(1, $this->namedObjectCount('pg_indexes', 'indexname', 'hk_p15_assignment_one_active_per_task'));
        $this->assertSame(1, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_p15_assignment_relationship_guard_trigger'));
        $this->assertSame(1, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_p15_assignment_immutability_guard_trigger'));
        $this->assertSame(1, $this->namedObjectCount('pg_trigger', 'tgname', 'hk_p15_assignment_task_consistency_trigger'));
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
