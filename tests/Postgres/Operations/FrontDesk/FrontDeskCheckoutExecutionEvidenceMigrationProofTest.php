<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionEvidenceMigrationProofTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private const PREFIX = 'ivorq_testing_fd_c1_migration_';

    public function test_migration_up_down_reapply_and_immutability_on_disposable_database(): void
    {
        $originalDatabase = config('database.connections.pgsql.database');
        $mainCount = Schema::hasTable('front_desk_checkout_executions') ? DB::table('front_desk_checkout_executions')->count() : 0;
        $database = self::PREFIX . strtolower((string) Str::ulid());

        $this->assertStringStartsWith(self::PREFIX, $database);
        $this->assertNotSame('ivorq_testing', $database);

        $admin = $this->adminPdo();
        $this->createDatabase($admin, $database);

        try {
            $this->switchDatabase($database);
            $this->createPrerequisites();

            $migration = require base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_23_000001_create_front_desk_checkout_executions_table.php');
            $migration->up();

            $this->assertTrue(Schema::hasTable('front_desk_checkout_executions'));

            // 19 columns
            $expectedColumns = [
                'id', 'property_id', 'front_desk_stay_id', 'reservation_id',
                'idempotency_key', 'terminal_stay_status', 'front_desk_final_review_id',
                'property_business_date_id', 'business_date',
                'night_audit_source_status', 'night_audit_source_fingerprint',
                'pms_financial_attestation_status', 'pms_financial_attestation_fingerprint',
                'general_cashier_attestation_status', 'general_cashier_attestation_fingerprint',
                'source_hash', 'occurred_at', 'created_by', 'created_at',
            ];
            $this->assertCount(19, $expectedColumns);
            foreach ($expectedColumns as $column) {
                $this->assertTrue(Schema::hasColumn('front_desk_checkout_executions', $column), "Column {$column} must exist.");
            }
            $this->assertFalse(Schema::hasColumn('front_desk_checkout_executions', 'updated_at'));

            $this->assertTrue($this->constraintExists('front_desk_checkout_executions_pkey'));

            foreach (['fd_ce_idempotency_unique', 'fd_ce_stay_unique', 'fd_ce_source_hash_unique'] as $uq) {
                $this->assertTrue($this->constraintExists($uq), "Unique constraint {$uq} must exist.");
            }
            foreach (['fd_ce_terminal_status_check', 'fd_ce_idempotency_not_blank',
                      'fd_ce_na_fingerprint_sha256', 'fd_ce_pms_fingerprint_sha256',
                      'fd_ce_gc_fingerprint_sha256', 'fd_ce_source_hash_sha256'] as $chk) {
                $this->assertTrue($this->constraintExists($chk), "CHECK constraint {$chk} must exist.");
            }

            // Six named FKs
            $fkNames = ['fd_ce_property_fk', 'fd_ce_stay_fk', 'fd_ce_reservation_fk',
                        'fd_ce_final_review_fk', 'fd_ce_business_date_fk', 'fd_ce_created_by_fk'];
            foreach ($fkNames as $fk) {
                $this->assertTrue($this->constraintExists($fk), "FK {$fk} must exist.");
                $confdeltype = DB::table('pg_constraint')->where('conname', $fk)->value('confdeltype');
                $this->assertSame('r', $confdeltype, "FK {$fk} must use RESTRICT (r), got: {$confdeltype}");
            }

            foreach (['fd_ce_property_id_idx', 'fd_ce_stay_id_idx', 'fd_ce_reservation_id_idx',
                      'fd_ce_final_review_id_idx', 'fd_ce_business_date_id_idx',
                      'fd_ce_occurred_at_idx', 'fd_ce_created_at_idx'] as $idx) {
                $this->assertTrue($this->indexExists($idx), "Index {$idx} must exist.");
            }

            $this->assertTrue($this->triggerExists('fd_ce_block_update'));
            $this->assertTrue($this->triggerExists('fd_ce_block_delete'));
            $this->assertTrue($this->functionExists('fd_ce_block_mutation'));

            // Seed source rows
            $propertyId = (string) Str::ulid();
            $stayId = (string) Str::ulid();
            $reservationId = (string) Str::ulid();
            $reviewId = (string) Str::ulid();
            $bdId = (string) Str::ulid();
            $actorId = (string) Str::ulid();

            DB::table('properties')->insert(['id' => $propertyId]);
            DB::table('front_desk_stays')->insert([
                'id' => $stayId, 'property_id' => $propertyId,
                'reservation_id' => $reservationId, 'guest_id' => (string) Str::ulid(),
                'status' => 'IN_HOUSE', 'created_by' => $actorId, 'updated_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('reservations')->insert([
                'id' => $reservationId, 'property_id' => $propertyId,
                'primary_guest_id' => (string) Str::ulid(),
                'reservation_number' => 'R-MIG-' . Str::upper(Str::random(4)),
                'status' => 'checked_in',
            ]);
            DB::table('front_desk_departure_checkout_final_reviews')->insert([
                'id' => $reviewId, 'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId, 'reservation_id' => $reservationId,
                'guest_id' => (string) Str::ulid(),
                'final_review_status' => 'CHECKOUT_FINAL_REVIEW_READY',
                'occurred_at' => now(), 'created_by' => $actorId,
                'idempotency_key' => 'dcfr-mig-' . Str::ulid(),
                'source_hash' => str_repeat('c', 64), 'created_at' => now(),
            ]);
            DB::table('property_business_dates')->insert([
                'id' => $bdId, 'property_id' => $propertyId,
                'business_date' => '2026-07-23', 'timezone_snapshot' => 'Asia/Makassar',
                'status' => 'Open', 'is_open' => true,
                'opened_by' => $actorId, 'opened_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('users')->insert(['id' => $actorId]);

            $rowId = (string) Str::ulid();
            $naFp = hash('sha256', 'na-mig');
            $pmsFp = hash('sha256', 'pms-mig');
            $gcFp = hash('sha256', 'gc-mig');
            $sourceHash = hash('sha256', 'source-mig');

            $insertEvidence = function (string $rid, string $ikey, string $sh) use ($propertyId, $stayId, $reservationId, $reviewId, $bdId, $actorId, $naFp, $pmsFp, $gcFp): void {
                DB::table('front_desk_checkout_executions')->insert([
                    'id' => $rid, 'property_id' => $propertyId,
                    'front_desk_stay_id' => $stayId, 'reservation_id' => $reservationId,
                    'idempotency_key' => $ikey, 'terminal_stay_status' => 'CHECKED_OUT',
                    'front_desk_final_review_id' => $reviewId, 'property_business_date_id' => $bdId,
                    'business_date' => '2026-07-23', 'night_audit_source_status' => 'NA_A2_CLEAR',
                    'night_audit_source_fingerprint' => $naFp, 'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
                    'pms_financial_attestation_fingerprint' => $pmsFp, 'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
                    'general_cashier_attestation_fingerprint' => $gcFp, 'source_hash' => $sh,
                    'occurred_at' => now(), 'created_by' => $actorId, 'created_at' => now(),
                ]);
            };

            $insertEvidence($rowId, 'migration-proof-1', $sourceHash);
            $this->assertSame(1, DB::table('front_desk_checkout_executions')->where('id', $rowId)->count());

            // FK violation: nonexistent references
            $this->assertFkInsertFails(['property_id' => (string) Str::ulid()]);
            $this->assertFkInsertFails(['front_desk_stay_id' => (string) Str::ulid()]);
            $this->assertFkInsertFails(['reservation_id' => (string) Str::ulid()]);
            $this->assertFkInsertFails(['front_desk_final_review_id' => (string) Str::ulid()]);
            $this->assertFkInsertFails(['property_business_date_id' => (string) Str::ulid()]);
            $this->assertFkInsertFails(['created_by' => (string) Str::ulid()]);
            $this->assertSame(1, DB::table('front_desk_checkout_executions')->count());

            // FK RESTRICT: deleting referenced rows blocked
            foreach ([
                'properties' => $propertyId, 'front_desk_stays' => $stayId,
                'reservations' => $reservationId, 'front_desk_departure_checkout_final_reviews' => $reviewId,
                'property_business_dates' => $bdId, 'users' => $actorId,
            ] as $table => $rid) {
                $this->assertRawDeleteReferencedFails($table, $rid);
            }

            // Idempotency whitespace (use valid source IDs to avoid FK interference)
            $badKeys = [' leading', 'trailing ', '   '];
            foreach ($badKeys as $bk) {
                $this->assertRawInsertIdempotencyFails(
                    $propertyId, $stayId, $reservationId, $reviewId, $bdId, $actorId,
                    $bk, 'fd_ce_idempotency_not_blank'
                );
            }
            // Canonical trimmed key accepted — use a separate stay for uniqueness
            $stayId2 = (string) Str::ulid();
            $reservationId2 = (string) Str::ulid();
            $reviewId2 = (string) Str::ulid();
            DB::table('front_desk_stays')->insert([
                'id' => $stayId2, 'property_id' => $propertyId,
                'reservation_id' => $reservationId2, 'guest_id' => (string) Str::ulid(),
                'status' => 'IN_HOUSE', 'created_by' => $actorId, 'updated_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('reservations')->insert([
                'id' => $reservationId2, 'property_id' => $propertyId,
                'primary_guest_id' => (string) Str::ulid(),
                'reservation_number' => 'R-MIG2-' . Str::upper(Str::random(4)),
                'status' => 'checked_in',
            ]);
            DB::table('front_desk_departure_checkout_final_reviews')->insert([
                'id' => $reviewId2, 'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId2, 'reservation_id' => $reservationId2,
                'guest_id' => (string) Str::ulid(),
                'final_review_status' => 'CHECKOUT_FINAL_REVIEW_READY',
                'occurred_at' => now(), 'created_by' => $actorId,
                'idempotency_key' => 'dcfr-mig2-' . Str::ulid(),
                'source_hash' => str_repeat('d', 64), 'created_at' => now(),
            ]);
            DB::table('front_desk_checkout_executions')->insert([
                'id' => (string) Str::ulid(), 'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId2, 'reservation_id' => $reservationId2,
                'idempotency_key' => 'canonical-trim', 'terminal_stay_status' => 'CHECKED_OUT',
                'front_desk_final_review_id' => $reviewId2, 'property_business_date_id' => $bdId,
                'business_date' => '2026-07-23', 'night_audit_source_status' => 'NA_A2_CLEAR',
                'night_audit_source_fingerprint' => $naFp, 'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
                'pms_financial_attestation_fingerprint' => $pmsFp, 'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
                'general_cashier_attestation_fingerprint' => $gcFp, 'source_hash' => hash('sha256', 'source-trim'),
                'occurred_at' => now(), 'created_by' => $actorId, 'created_at' => now(),
            ]);

            // Immutability
            $this->assertRawUpdateFails($rowId, ['night_audit_source_status' => 'MUTATED']);
            $this->assertRawDeleteFails($rowId);
            $replacementId = (string) Str::ulid();
            $this->assertRawUpdateFails($rowId, ['id' => $replacementId]);
            $this->assertSame(0, DB::table('front_desk_checkout_executions')->where('id', $replacementId)->count());
            $this->assertSame(1, DB::table('front_desk_checkout_executions')->where('id', $rowId)->count());
            $row = DB::table('front_desk_checkout_executions')->where('id', $rowId)->first();
            $this->assertNotNull($row);
            $this->assertSame('CHECKED_OUT', $row->terminal_stay_status);

            // DOWN
            $migration->down();
            $this->assertFalse(Schema::hasTable('front_desk_checkout_executions'));
            $this->assertFalse($this->triggerExists('fd_ce_block_update'));
            $this->assertFalse($this->triggerExists('fd_ce_block_delete'));
            $this->assertFalse($this->functionExists('fd_ce_block_mutation'));

            // REAPPLY
            $migration = require base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_23_000001_create_front_desk_checkout_executions_table.php');
            $migration->up();
            $this->assertTrue(Schema::hasTable('front_desk_checkout_executions'));

            foreach ($fkNames as $fk) {
                $this->assertTrue($this->constraintExists($fk), "FK {$fk} must exist after REAPPLY.");
            }
            foreach (['fd_ce_idempotency_unique', 'fd_ce_stay_unique', 'fd_ce_source_hash_unique'] as $uq) {
                $this->assertTrue($this->constraintExists($uq));
            }
            foreach (['fd_ce_terminal_status_check', 'fd_ce_idempotency_not_blank',
                      'fd_ce_na_fingerprint_sha256', 'fd_ce_pms_fingerprint_sha256',
                      'fd_ce_gc_fingerprint_sha256', 'fd_ce_source_hash_sha256'] as $chk) {
                $this->assertTrue($this->constraintExists($chk));
            }
            foreach (['fd_ce_property_id_idx', 'fd_ce_stay_id_idx', 'fd_ce_reservation_id_idx',
                      'fd_ce_final_review_id_idx', 'fd_ce_business_date_id_idx',
                      'fd_ce_occurred_at_idx', 'fd_ce_created_at_idx'] as $idx) {
                $this->assertTrue($this->indexExists($idx));
            }
            $this->assertTrue($this->triggerExists('fd_ce_block_update'));
            $this->assertTrue($this->triggerExists('fd_ce_block_delete'));
            $this->assertTrue($this->functionExists('fd_ce_block_mutation'));

            $insertEvidence($rowId, 'migration-proof-3', hash('sha256', 'source-mig-3'));
            $this->assertRawUpdateFails($rowId, ['night_audit_source_status' => 'MUTATED_AFTER_REAPPLY']);
            $this->assertRawDeleteFails($rowId);
            $this->assertFkInsertFails(['property_id' => (string) Str::ulid()]);
            $this->assertRawDeleteReferencedFails('properties', $propertyId);
        } finally {
            $this->switchDatabase($originalDatabase);
            $this->dropDatabase($admin, $database);
        }

        $this->assertSame('ivorq_testing', config('database.connections.pgsql.database'));
        $this->assertSame($mainCount, Schema::hasTable('front_desk_checkout_executions') ? DB::table('front_desk_checkout_executions')->count() : 0);
    }

    private function createPrerequisites(): void
    {
        Schema::create('properties', function ($table): void { $table->char('id', 26)->primary(); });
        Schema::create('front_desk_stays', function ($table): void {
            $table->char('id', 26)->primary(); $table->char('property_id', 26);
            $table->char('reservation_id', 26); $table->char('guest_id', 26);
            $table->string('status'); $table->string('created_by', 26);
            $table->string('updated_by', 26); $table->timestamps();
        });
        Schema::create('reservations', function ($table): void {
            $table->char('id', 26)->primary(); $table->char('property_id', 26);
            $table->char('primary_guest_id', 26); $table->string('reservation_number');
            $table->string('status');
        });
        Schema::create('front_desk_departure_checkout_final_reviews', function ($table): void {
            $table->char('id', 26)->primary(); $table->char('property_id', 26);
            $table->char('front_desk_stay_id', 26); $table->char('reservation_id', 26);
            $table->char('guest_id', 26); $table->string('final_review_status', 50);
            $table->timestamp('occurred_at'); $table->string('created_by', 26);
            $table->string('idempotency_key'); $table->string('source_hash');
            $table->timestamp('created_at');
        });
        Schema::create('property_business_dates', function ($table): void {
            $table->char('id', 26)->primary(); $table->char('property_id', 26);
            $table->date('business_date'); $table->string('timezone_snapshot')->nullable();
            $table->string('status'); $table->boolean('is_open')->nullable();
            $table->string('opened_by', 26)->nullable(); $table->timestamp('opened_at')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function ($table): void { $table->char('id', 26)->primary(); });
    }

    private function assertRawUpdateFails(string $rowId, array $values): void
    {
        try {
            DB::table('front_desk_checkout_executions')->where('id', $rowId)->update($values);
            $this->fail('Raw update should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FD_C1_CHECKOUT_EXECUTION_EVIDENCE_IMMUTABLE', $e->getMessage());
        }
    }

    private function assertRawDeleteFails(string $rowId): void
    {
        try {
            DB::table('front_desk_checkout_executions')->where('id', $rowId)->delete();
            $this->fail('Raw delete should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FD_C1_CHECKOUT_EXECUTION_EVIDENCE_IMMUTABLE', $e->getMessage());
        }
    }

    private function assertFkInsertFails(array $overrides): void
    {
        $base = [
            'id' => (string) Str::ulid(), 'property_id' => 'P', 'front_desk_stay_id' => 'S',
            'reservation_id' => 'R', 'idempotency_key' => 'fk-' . Str::ulid(),
            'terminal_stay_status' => 'CHECKED_OUT', 'front_desk_final_review_id' => 'F',
            'property_business_date_id' => 'B', 'business_date' => '2026-07-23',
            'night_audit_source_status' => 'NA_A2_CLEAR',
            'night_audit_source_fingerprint' => str_repeat('a', 64),
            'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
            'pms_financial_attestation_fingerprint' => str_repeat('b', 64),
            'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
            'general_cashier_attestation_fingerprint' => str_repeat('c', 64),
            'source_hash' => hash('sha256', 'fk-src-' . Str::ulid()),
            'occurred_at' => now(), 'created_by' => 'U', 'created_at' => now(),
        ];
        $payload = array_merge($base, $overrides);
        try {
            DB::table('front_desk_checkout_executions')->insert($payload);
            $this->fail('Raw insert with bad FK should have failed: ' . json_encode($overrides));
        } catch (QueryException $e) {
            $this->assertStringContainsString('violates', $e->getMessage());
        }
    }

    private function assertRawDeleteReferencedFails(string $table, string $id): void
    {
        try {
            DB::table($table)->where('id', $id)->delete();
            $this->fail("Deleting referenced row from {$table} should have been blocked.");
        } catch (QueryException $e) {
            $this->assertStringContainsString('violates', $e->getMessage());
            $this->assertStringContainsString('foreign key constraint', $e->getMessage());
        }
    }

    private function assertRawInsertIdempotencyFails(
        string $propertyId, string $stayId, string $reservationId,
        string $reviewId, string $bdId, string $actorId,
        string $badKey, string $expectedConstraint
    ): void {
        try {
            DB::table('front_desk_checkout_executions')->insert([
                'id' => (string) Str::ulid(), 'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId, 'reservation_id' => $reservationId,
                'idempotency_key' => $badKey, 'terminal_stay_status' => 'CHECKED_OUT',
                'front_desk_final_review_id' => $reviewId,
                'property_business_date_id' => $bdId,
                'business_date' => '2026-07-23', 'night_audit_source_status' => 'NA',
                'night_audit_source_fingerprint' => str_repeat('a', 64),
                'pms_financial_attestation_status' => 'PF',
                'pms_financial_attestation_fingerprint' => str_repeat('b', 64),
                'general_cashier_attestation_status' => 'GC',
                'general_cashier_attestation_fingerprint' => str_repeat('c', 64),
                'source_hash' => hash('sha256', 'idem-' . Str::ulid()),
                'occurred_at' => now(), 'created_by' => $actorId,
                'created_at' => now(),
            ]);
            $this->fail("Raw insert with bad idempotency key '{$badKey}' should have failed.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($expectedConstraint, $e->getMessage());
        }
    }

    private function constraintExists(string $name): bool { return DB::table('pg_constraint')->where('conname', $name)->exists(); }
    private function indexExists(string $name): bool { return DB::table('pg_indexes')->where('indexname', $name)->exists(); }
    private function triggerExists(string $name): bool { return DB::table('pg_trigger')->where('tgname', $name)->where('tgisinternal', false)->exists(); }
    private function functionExists(string $name): bool { return DB::table('pg_proc')->where('proname', $name)->exists(); }

    private function adminPdo(): PDO
    {
        $config = config('database.connections.pgsql');
        return new PDO(sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function createDatabase(PDO $pdo, string $database): void { $pdo->exec('CREATE DATABASE ' . $this->quoteIdentifier($database)); }

    private function dropDatabase(PDO $pdo, string $database): void
    {
        $this->assertStringStartsWith(self::PREFIX, $database);
        $pdo->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()')->execute([$database]);
        $pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database));
    }

    private function switchDatabase(string $database): void
    {
        config(['database.connections.pgsql.database' => $database]);
        DB::purge('pgsql'); DB::reconnect('pgsql'); Schema::connection('pgsql');
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $identifier)) { throw new \RuntimeException('Unsafe disposable database identifier.'); }
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
