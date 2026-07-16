<?php

namespace Tests\Postgres\Foundation;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Tests\PostgresTestCase;

class PropertyBusinessDateMigrationProofTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private const PREFIX = 'ivorq_testing_bd_a1_migration_';

    public function test_migration_up_down_reapply_and_raw_database_guards_on_disposable_database(): void
    {
        $originalDatabase = config('database.connections.pgsql.database');
        $mainCount = Schema::hasTable('property_business_dates') ? DB::table('property_business_dates')->count() : 0;
        $database = self::PREFIX . strtolower((string) Str::ulid());

        $this->assertStringStartsWith(self::PREFIX, $database);
        $this->assertNotSame('ivorq_testing', $database);
        $this->assertNotSame('ivorq_testing_corrupt_20260715_182047', $database);

        $admin = $this->adminPdo();
        $this->createDatabase($admin, $database);

        try {
            $this->switchDatabase($database);
            $this->createPrerequisites();
            (require base_path('database/migrations/2026_06_21_000001_create_property_business_dates_table.php'))->up();
            (require base_path('database/migrations/2026_06_21_213513_correct_property_business_dates_check_constraint.php'))->up();

            $migration = require base_path('database/migrations/2026_07_16_000001_add_bd_a1_timezone_snapshot_and_immutability_to_property_business_dates.php');
            $migration->up();

            $this->assertTrue(Schema::hasColumn('property_business_dates', 'timezone_snapshot'));
            $this->assertTrue($this->constraintExists('chk_property_business_dates_timezone_snapshot_nonblank'));
            $this->assertTrue($this->triggerExists('trg_property_business_dates_bd_a1_foundation_guard'));
            $this->assertTrue($this->functionExists('property_business_dates_bd_a1_foundation_guard'));

            $propertyId = (string) Str::ulid();
            DB::table('properties')->insert(['id' => $propertyId]);
            $rowId = (string) Str::ulid();
            DB::table('property_business_dates')->insert([
                'id' => $rowId,
                'property_id' => $propertyId,
                'business_date' => '2026-07-16',
                'timezone_snapshot' => null,
                'status' => 'Open',
                'is_open' => true,
                'opened_by' => (string) Str::ulid(),
                'opened_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertRawInsertFails([
                'id' => (string) Str::ulid(),
                'property_id' => $propertyId,
                'business_date' => '2026-07-17',
                'timezone_snapshot' => '   ',
                'status' => 'Closed',
                'is_open' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('property_business_dates')->where('id', $rowId)->update([
                'status' => 'Closed',
                'is_open' => null,
                'closed_by' => (string) Str::ulid(),
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertSame('Closed', DB::table('property_business_dates')->where('id', $rowId)->value('status'));

            $this->assertRawUpdateFails($rowId, ['business_date' => '2026-07-18']);
            $this->assertRawUpdateFails($rowId, ['property_id' => (string) Str::ulid()]);
            $this->assertRawUpdateFails($rowId, ['timezone_snapshot' => 'UTC']);
            $this->assertRawUpdateFails($rowId, ['opened_by' => (string) Str::ulid()]);
            $this->assertRawUpdateFails($rowId, ['opened_at' => now()->addMinute()]);
            $this->assertRawDeleteFails($rowId);

            $migration->down();
            $this->assertFalse(Schema::hasColumn('property_business_dates', 'timezone_snapshot'));
            $this->assertFalse($this->triggerExists('trg_property_business_dates_bd_a1_foundation_guard'));
            $this->assertFalse($this->functionExists('property_business_dates_bd_a1_foundation_guard'));

            $migration = require base_path('database/migrations/2026_07_16_000001_add_bd_a1_timezone_snapshot_and_immutability_to_property_business_dates.php');
            $migration->up();
            $this->assertTrue(Schema::hasColumn('property_business_dates', 'timezone_snapshot'));
            $this->assertTrue($this->triggerExists('trg_property_business_dates_bd_a1_foundation_guard'));
        } finally {
            $this->switchDatabase($originalDatabase);
            $this->dropDatabase($admin, $database);
        }

        $this->assertSame('ivorq_testing', config('database.connections.pgsql.database'));
        $this->assertSame($mainCount, Schema::hasTable('property_business_dates') ? DB::table('property_business_dates')->count() : 0);
    }

    private function createPrerequisites(): void
    {
        Schema::create('properties', function ($table): void {
            $table->char('id', 26)->primary();
        });
    }

    private function assertRawInsertFails(array $values): void
    {
        try {
            DB::table('property_business_dates')->insert($values);
            $this->fail('Raw insert should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_property_business_dates_timezone_snapshot_nonblank', $e->getMessage());
        }
    }

    private function assertRawUpdateFails(string $rowId, array $values): void
    {
        try {
            DB::table('property_business_dates')->where('id', $rowId)->update($values);
            $this->fail('Raw foundational update should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('BD_A1_PROPERTY_BUSINESS_DATE_FOUNDATION_IMMUTABLE', $e->getMessage());
        }
    }

    private function assertRawDeleteFails(string $rowId): void
    {
        try {
            DB::table('property_business_dates')->where('id', $rowId)->delete();
            $this->fail('Raw delete should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('BD_A1_PROPERTY_BUSINESS_DATE_DELETE_REJECTED', $e->getMessage());
        }
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('pg_constraint')->where('conname', $name)->exists();
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
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=postgres',
            $config['host'],
            $config['port']
        );

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
        $pdo->prepare("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()")
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
