<?php

namespace Tests\Postgres\Operations\NightAudit;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Tests\PostgresTestCase;

class NightAuditRunMigrationProofTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private const PREFIX = 'ivorq_testing_na_a1_migration_';

    public function test_migration_up_down_reapply_and_database_guards_on_disposable_database(): void
    {
        $originalDatabase = config('database.connections.pgsql.database');
        $mainCount = Schema::hasTable('night_audit_runs') ? DB::table('night_audit_runs')->count() : 0;
        $database = self::PREFIX . strtolower((string) Str::ulid());

        $this->assertStringStartsWith(self::PREFIX, $database);
        $this->assertNotSame('ivorq_testing', $database);
        $this->assertNotSame('ivorq_testing_corrupt_20260715_182047', $database);

        $admin = $this->adminPdo();
        $this->createDatabase($admin, $database);

        try {
            $this->switchDatabase($database);
            $this->createPrerequisites();

            $migration = require base_path('database/migrations/2026_07_17_000001_create_night_audit_runs_table.php');
            $migration->up();

            $this->assertTrue(Schema::hasTable('night_audit_runs'));
            foreach ([
                'id',
                'property_id',
                'property_business_date_id',
                'business_date_snapshot',
                'property_timezone_snapshot',
                'attempt_number',
                'status',
                'started_by',
                'started_at',
                'aborted_by',
                'aborted_at',
                'abort_reason',
                'created_by',
                'updated_by',
            ] as $column) {
                $this->assertTrue(Schema::hasColumn('night_audit_runs', $column), "{$column} must exist.");
            }
            $this->assertTrue($this->constraintExists('chk_night_audit_runs_attempt_positive'));
            $this->assertTrue($this->constraintExists('chk_night_audit_runs_timezone_nonblank'));
            $this->assertTrue($this->constraintExists('chk_night_audit_runs_status_valid'));
            $this->assertTrue($this->constraintExists('chk_night_audit_runs_abort_fields'));
            $this->assertTrue($this->indexExists('uq_night_audit_runs_one_active_per_property'));
            $this->assertTrue($this->triggerExists('trg_night_audit_runs_na_a1_evidence_guard'));
            $this->assertTrue($this->functionExists('night_audit_runs_na_a1_evidence_guard'));

            [$propertyId, $businessDateId, $actorId] = $this->seedSourceRows();
            $runId = $this->insertRun($propertyId, $businessDateId, $actorId, 1, 'IN_PROGRESS');

            $this->assertRawInsertFails($propertyId, $businessDateId, $actorId, 0, 'IN_PROGRESS', 'chk_night_audit_runs_attempt_positive');
            $this->assertRawInsertFails($propertyId, $businessDateId, $actorId, 2, 'COMPLETED', 'violates check constraint');
            $this->assertRawInsertFails($propertyId, $businessDateId, $actorId, 2, 'IN_PROGRESS', 'uq_night_audit_runs_one_active_per_property');

            DB::table('night_audit_runs')->where('id', $runId)->update([
                'status' => 'ABORTED',
                'aborted_by' => $actorId,
                'aborted_at' => now(),
                'abort_reason' => 'Controlled migration proof abort.',
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);
            $this->assertSame('ABORTED', DB::table('night_audit_runs')->where('id', $runId)->value('status'));

            $this->assertRawUpdateFails($runId, ['status' => 'IN_PROGRESS'], 'NA_A1_NIGHT_AUDIT_RUN_UPDATE_REJECTED');
            $this->assertRawUpdateFails($runId, ['abort_reason' => 'Second update'], 'NA_A1_NIGHT_AUDIT_RUN_UPDATE_REJECTED');
            $this->assertRawUpdateFails($runId, ['business_date_snapshot' => '2026-07-18'], 'NA_A1_NIGHT_AUDIT_RUN_FOUNDATION_IMMUTABLE');
            $this->assertRawDeleteFails($runId);

            $migration->down();
            $this->assertFalse(Schema::hasTable('night_audit_runs'));
            $this->assertFalse($this->functionExists('night_audit_runs_na_a1_evidence_guard'));

            $migration = require base_path('database/migrations/2026_07_17_000001_create_night_audit_runs_table.php');
            $migration->up();
            $this->assertTrue(Schema::hasTable('night_audit_runs'));
            $this->assertTrue($this->indexExists('uq_night_audit_runs_one_active_per_property'));
        } finally {
            $this->switchDatabase($originalDatabase);
            $this->dropDatabase($admin, $database);
        }

        $this->assertSame('ivorq_testing', config('database.connections.pgsql.database'));
        $this->assertSame($mainCount, Schema::hasTable('night_audit_runs') ? DB::table('night_audit_runs')->count() : 0);
    }

    private function createPrerequisites(): void
    {
        Schema::create('properties', function ($table): void {
            $table->char('id', 26)->primary();
        });
        Schema::create('users', function ($table): void {
            $table->char('id', 26)->primary();
        });
        Schema::create('property_business_dates', function ($table): void {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->date('business_date');
            $table->string('timezone_snapshot')->nullable();
            $table->string('status');
            $table->boolean('is_open')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function seedSourceRows(): array
    {
        $propertyId = (string) Str::ulid();
        $businessDateId = (string) Str::ulid();
        $actorId = (string) Str::ulid();

        DB::table('properties')->insert(['id' => $propertyId]);
        DB::table('users')->insert(['id' => $actorId]);
        DB::table('property_business_dates')->insert([
            'id' => $businessDateId,
            'property_id' => $propertyId,
            'business_date' => '2026-07-17',
            'timezone_snapshot' => 'Asia/Makassar',
            'status' => 'Open',
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$propertyId, $businessDateId, $actorId];
    }

    private function insertRun(string $propertyId, string $businessDateId, string $actorId, int $attempt, string $status): string
    {
        $id = (string) Str::ulid();
        DB::table('night_audit_runs')->insert([
            'id' => $id,
            'property_id' => $propertyId,
            'property_business_date_id' => $businessDateId,
            'business_date_snapshot' => '2026-07-17',
            'property_timezone_snapshot' => 'Asia/Makassar',
            'attempt_number' => $attempt,
            'status' => $status,
            'started_by' => $actorId,
            'started_at' => now(),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assertRawInsertFails(string $propertyId, string $businessDateId, string $actorId, int $attempt, string $status, string $message): void
    {
        try {
            $this->insertRun($propertyId, $businessDateId, $actorId, $attempt, $status);
            $this->fail('Raw insert should have failed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function assertRawUpdateFails(string $runId, array $values, string $message): void
    {
        try {
            DB::table('night_audit_runs')->where('id', $runId)->update($values + ['updated_at' => now()]);
            $this->fail('Raw update should have failed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function assertRawDeleteFails(string $runId): void
    {
        try {
            DB::table('night_audit_runs')->where('id', $runId)->delete();
            $this->fail('Raw delete should have failed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('NA_A1_NIGHT_AUDIT_RUN_DELETE_REJECTED', $exception->getMessage());
        }
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('pg_constraint')->where('conname', $name)->exists();
    }

    private function indexExists(string $name): bool
    {
        return DB::table('pg_indexes')->where('indexname', $name)->exists();
    }

    private function triggerExists(string $name): bool
    {
        return DB::table('pg_trigger')->where('tgname', $name)->where('tgisinternal', false)->exists();
    }

    private function functionExists(string $name): bool
    {
        return DB::table('pg_proc')->where('proname', $name)->exists();
    }

    private function adminPdo(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']);

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function createDatabase(PDO $pdo, string $database): void
    {
        $pdo->exec('CREATE DATABASE ' . $this->quoteIdentifier($database));
    }

    private function dropDatabase(PDO $pdo, string $database): void
    {
        $this->assertStringStartsWith(self::PREFIX, $database);
        $pdo->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()')
            ->execute([$database]);
        $pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database));
    }

    private function switchDatabase(string $database): void
    {
        config(['database.connections.pgsql.database' => $database]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
        Schema::connection('pgsql');
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $identifier)) {
            throw new \RuntimeException('Unsafe disposable database identifier.');
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
