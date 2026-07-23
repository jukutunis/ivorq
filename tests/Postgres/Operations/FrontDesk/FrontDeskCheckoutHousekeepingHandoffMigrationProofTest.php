<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Tests\PostgresTestCase;

class FrontDeskCheckoutHousekeepingHandoffMigrationProofTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private const PREFIX = 'ivorq_testing_fd_c2_migration_';

    public function test_migration_up_down_reapply_and_integrity_on_disposable_database(): void
    {
        $originalDatabase = config('database.connections.pgsql.database');
        $mainCount = Schema::hasTable('front_desk_checkout_housekeeping_handoffs')
            ? DB::table('front_desk_checkout_housekeeping_handoffs')->count() : 0;
        $database = self::PREFIX . strtolower((string) Str::ulid());

        $this->assertStringStartsWith(self::PREFIX, $database);
        $this->assertNotSame('ivorq_testing', $database);

        $admin = $this->adminPdo();
        $this->createDatabase($admin, $database);

        try {
            $this->switchDatabase($database);
            $this->createPrerequisites();

            $migration = require base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_24_000001_create_front_desk_checkout_housekeeping_handoffs_table.php');
            $migration->up();

            $this->assertTrue(Schema::hasTable('front_desk_checkout_housekeeping_handoffs'));

            // 22 columns
            $expectedColumns = [
                'id', 'property_id', 'front_desk_stay_id', 'reservation_id',
                'checkout_execution_id', 'property_business_date_id', 'business_date',
                'idempotency_key', 'correlation_key', 'source_hash',
                'delivery_status', 'attempts', 'available_at',
                'claimed_at', 'claim_expires_at', 'claim_token_hash',
                'delivered_at', 'failed_at', 'last_error_code',
                'occurred_at', 'created_at', 'updated_at',
            ];
            $this->assertCount(22, $expectedColumns);
            foreach ($expectedColumns as $column) {
                $this->assertTrue(
                    Schema::hasColumn('front_desk_checkout_housekeeping_handoffs', $column),
                    "Column {$column} must exist."
                );
            }

            // Primary key
            $this->assertTrue($this->constraintExists('front_desk_checkout_housekeeping_handoffs_pkey'));

            // Five unique constraints
            foreach ([
                'fd_chh_idempotency_unique',
                'fd_chh_correlation_unique',
                'fd_chh_source_hash_unique',
                'fd_chh_execution_unique',
                'fd_chh_stay_unique',
            ] as $uq) {
                $this->assertTrue($this->constraintExists($uq), "Unique constraint {$uq} must exist.");
            }

            // All CHECK constraints
            foreach ([
                'fd_chh_status_check',
                'fd_chh_idempotency_check',
                'fd_chh_correlation_check',
                'fd_chh_source_hash_check',
                'fd_chh_claim_hash_check',
                'fd_chh_attempts_check',
                'fd_chh_error_code_check',
                'fd_chh_claim_timing_check',
                'fd_chh_state_shape_check',
            ] as $chk) {
                $this->assertTrue($this->constraintExists($chk), "CHECK constraint {$chk} must exist.");
            }

            // Five FKs
            $fkNames = [
                'fd_chh_property_fk',
                'fd_chh_stay_fk',
                'fd_chh_reservation_fk',
                'fd_chh_execution_fk',
                'fd_chh_business_date_fk',
            ];
            foreach ($fkNames as $fk) {
                $this->assertTrue($this->constraintExists($fk), "FK {$fk} must exist.");
                $confdeltype = DB::table('pg_constraint')->where('conname', $fk)->value('confdeltype');
                $this->assertSame('r', $confdeltype, "FK {$fk} must use RESTRICT (r), got: {$confdeltype}");
            }

            // All indexes
            foreach ([
                'fd_chh_property_id_idx',
                'fd_chh_stay_id_idx',
                'fd_chh_reservation_id_idx',
                'fd_chh_business_date_id_idx',
                'fd_chh_delivery_status_idx',
                'fd_chh_available_at_idx',
                'fd_chh_claim_expires_at_idx',
                'fd_chh_occurred_at_idx',
                'fd_chh_created_at_idx',
                'fd_chh_claimable_idx',
            ] as $idx) {
                $this->assertTrue($this->indexExists($idx), "Index {$idx} must exist.");
            }

            // Both triggers
            $this->assertTrue($this->triggerExists('fd_chh_check_source'));
            $this->assertTrue($this->triggerExists('fd_chh_enforce_mutation'));

            // Both functions
            $this->assertTrue($this->functionExists('fd_chh_check_source_relationship'));
            $this->assertTrue($this->functionExists('fd_chh_enforce_mutation_rules'));

            // Seed source rows for FK/integrity testing
            $propertyId = (string) Str::ulid();
            $stayId = (string) Str::ulid();
            $stayId2 = (string) Str::ulid();
            $reservationId = (string) Str::ulid();
            $reservationId2 = (string) Str::ulid();
            $reviewId = (string) Str::ulid();
            $reviewId2 = (string) Str::ulid();
            $bdId = (string) Str::ulid();
            $actorId = (string) Str::ulid();
            $executionId = (string) Str::ulid();
            $executionId2 = (string) Str::ulid();

            DB::table('properties')->insert(['id' => $propertyId]);
            DB::table('front_desk_stays')->insert([
                'id' => $stayId, 'property_id' => $propertyId,
                'reservation_id' => $reservationId, 'guest_id' => (string) Str::ulid(),
                'status' => 'IN_HOUSE', 'created_by' => $actorId, 'updated_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('front_desk_stays')->insert([
                'id' => $stayId2, 'property_id' => $propertyId,
                'reservation_id' => $reservationId2, 'guest_id' => (string) Str::ulid(),
                'status' => 'IN_HOUSE', 'created_by' => $actorId, 'updated_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('reservations')->insert([
                'id' => $reservationId, 'property_id' => $propertyId,
                'primary_guest_id' => (string) Str::ulid(),
                'reservation_number' => 'R-C2F-' . Str::upper(Str::random(4)),
                'status' => 'checked_in',
            ]);
            DB::table('reservations')->insert([
                'id' => $reservationId2, 'property_id' => $propertyId,
                'primary_guest_id' => (string) Str::ulid(),
                'reservation_number' => 'R-C2G-' . Str::upper(Str::random(4)),
                'status' => 'checked_in',
            ]);
            DB::table('front_desk_departure_checkout_final_reviews')->insert([
                'id' => $reviewId, 'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId, 'reservation_id' => $reservationId,
                'guest_id' => (string) Str::ulid(),
                'final_review_status' => 'CHECKOUT_FINAL_REVIEW_READY',
                'occurred_at' => now(), 'created_by' => $actorId,
                'idempotency_key' => 'dcfr-c2f-' . Str::ulid(),
                'source_hash' => str_repeat('c', 64), 'created_at' => now(),
            ]);
            DB::table('front_desk_departure_checkout_final_reviews')->insert([
                'id' => $reviewId2, 'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId2, 'reservation_id' => $reservationId2,
                'guest_id' => (string) Str::ulid(),
                'final_review_status' => 'CHECKOUT_FINAL_REVIEW_READY',
                'occurred_at' => now(), 'created_by' => $actorId,
                'idempotency_key' => 'dcfr-c2g-' . Str::ulid(),
                'source_hash' => str_repeat('d', 64), 'created_at' => now(),
            ]);
            DB::table('property_business_dates')->insert([
                'id' => $bdId, 'property_id' => $propertyId,
                'business_date' => '2026-07-24', 'timezone_snapshot' => 'Asia/Makassar',
                'status' => 'Open', 'is_open' => true,
                'opened_by' => $actorId, 'opened_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('users')->insert(['id' => $actorId]);

            // Insert valid checkout execution (CHECKED_OUT)
            DB::table('front_desk_checkout_executions')->insert([
                'id' => $executionId, 'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId, 'reservation_id' => $reservationId,
                'idempotency_key' => 'ce-c2f-' . Str::ulid(),
                'terminal_stay_status' => 'CHECKED_OUT',
                'front_desk_final_review_id' => $reviewId,
                'property_business_date_id' => $bdId,
                'business_date' => '2026-07-24',
                'night_audit_source_status' => 'NA_A2_CLEAR',
                'night_audit_source_fingerprint' => str_repeat('a', 64),
                'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
                'pms_financial_attestation_fingerprint' => str_repeat('b', 64),
                'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
                'general_cashier_attestation_fingerprint' => str_repeat('c', 64),
                'source_hash' => str_repeat('e', 64),
                'occurred_at' => now(),
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            // Second valid checkout execution
            DB::table('front_desk_checkout_executions')->insert([
                'id' => $executionId2, 'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId2, 'reservation_id' => $reservationId2,
                'idempotency_key' => 'ce-c2g-' . Str::ulid(),
                'terminal_stay_status' => 'CHECKED_OUT',
                'front_desk_final_review_id' => $reviewId2,
                'property_business_date_id' => $bdId,
                'business_date' => '2026-07-24',
                'night_audit_source_status' => 'NA_A2_CLEAR',
                'night_audit_source_fingerprint' => str_repeat('a', 64),
                'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
                'pms_financial_attestation_fingerprint' => str_repeat('b', 64),
                'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
                'general_cashier_attestation_fingerprint' => str_repeat('c', 64),
                'source_hash' => str_repeat('f', 64),
                'occurred_at' => now(),
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            $handoffId = (string) Str::ulid();

            $insertHandoff = function (string $hid, string $ikey, string $ckey, string $execId, string $stay) use (
                $propertyId, $stayId, $reservationId, $bdId, $actorId
            ): void {
                DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
                    'id' => $hid, 'property_id' => $propertyId,
                    'front_desk_stay_id' => $stay, 'reservation_id' => $reservationId,
                    'checkout_execution_id' => $execId, 'property_business_date_id' => $bdId,
                    'business_date' => '2026-07-24',
                    'idempotency_key' => $ikey, 'correlation_key' => $ckey,
                    'source_hash' => hash('sha256', $hid . $ikey),
                    'delivery_status' => 'PENDING', 'attempts' => 0,
                    'available_at' => now(), 'occurred_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            };

            $insertHandoff($handoffId, 'mig-proof-1', 'corr-mig-1', $executionId, $stayId);
            $this->assertSame(1, DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $handoffId)->count());

            // FK violations
            $this->assertFkInsertFails($propertyId, $stayId, $reservationId, $executionId, $bdId, ['property_id' => (string) Str::ulid()]);
            $this->assertFkInsertFails($propertyId, $stayId, $reservationId, $executionId, $bdId, ['front_desk_stay_id' => (string) Str::ulid()]);
            $this->assertFkInsertFails($propertyId, $stayId, $reservationId, $executionId, $bdId, ['reservation_id' => (string) Str::ulid()]);
            $this->assertFkInsertFails($propertyId, $stayId, $reservationId, $executionId, $bdId, ['checkout_execution_id' => (string) Str::ulid()]);
            $this->assertFkInsertFails($propertyId, $stayId, $reservationId, $executionId, $bdId, ['property_business_date_id' => (string) Str::ulid()]);
            $this->assertSame(1, DB::table('front_desk_checkout_housekeeping_handoffs')->count());

            // FK RESTRICT: deleting referenced rows blocked
            foreach ([
                'properties' => $propertyId,
                'front_desk_stays' => $stayId,
                'reservations' => $reservationId,
                'front_desk_checkout_executions' => $executionId,
                'property_business_dates' => $bdId,
            ] as $table => $rid) {
                $this->assertRawDeleteReferencedFails($table, $rid);
            }

            // Idempotency whitespace
            foreach ([' leading', 'trailing ', '   '] as $bk) {
                $this->assertRawInsertKeyFails(
                    $propertyId, $stayId, $reservationId, $executionId, $bdId,
                    $bk, 'corr-ws-' . Str::ulid(), 'fd_chh_idempotency_check'
                );
            }

            // Correlation whitespace
            foreach ([' leading', 'trailing ', '   '] as $bk) {
                $this->assertRawInsertKeyFails(
                    $propertyId, $stayId2, $reservationId2, $executionId2, $bdId,
                    'idem-wsck-' . Str::ulid(), $bk, 'fd_chh_correlation_check'
                );
            }

            // Invalid source hash
            $this->assertRawInsertKeyFails(
                $propertyId, $stayId, $reservationId, $executionId, $bdId,
                'idem-badhash-' . Str::ulid(), 'corr-badhash-' . Str::ulid(),
                'fd_chh_source_hash_check',
                ['source_hash' => 'NOT-A-HEX-HASH']
            );

            // Invalid status (caught by state_shape_check before status_check)
            $this->assertRawInsertKeyFails(
                $propertyId, $stayId, $reservationId, $executionId, $bdId,
                'idem-badst-' . Str::ulid(), 'corr-badst-' . Str::ulid(),
                'fd_chh_state_shape_check',
                ['delivery_status' => 'PROCESSING']
            );

            // Immutability
            $this->assertRawUpdateFails($handoffId, ['idempotency_key' => 'mutated']);
            $this->assertRawDeleteFails($handoffId);
            $this->assertSame(1, DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $handoffId)->count());

            // Source relationship mismatch
            $this->assertRawInsertSourceMismatchFails(
                $propertyId, $stayId2, $reservationId2, $executionId, $bdId
            );

            // Invalid transition
            // Try to go PENDING -> DELIVERED
            DB::table('front_desk_checkout_housekeeping_handoffs')
                ->where('id', $handoffId)
                ->update(['delivery_status' => 'CLAIMED',
                          'claimed_at' => now(),
                          'claim_expires_at' => now()->addSeconds(60),
                          'claim_token_hash' => str_repeat('a', 64),
                          'available_at' => now(),
                          'updated_at' => now()]);
            try {
                DB::table('front_desk_checkout_housekeeping_handoffs')
                    ->where('id', $handoffId)->update(['delivery_status' => 'PENDING']);
                $this->fail('Invalid transition should have failed.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION', $e->getMessage());
            }

            // DOWN
            $migration->down();
            $this->assertFalse(Schema::hasTable('front_desk_checkout_housekeeping_handoffs'));
            $this->assertFalse($this->triggerExists('fd_chh_check_source'));
            $this->assertFalse($this->triggerExists('fd_chh_enforce_mutation'));
            $this->assertFalse($this->functionExists('fd_chh_check_source_relationship'));
            $this->assertFalse($this->functionExists('fd_chh_enforce_mutation_rules'));

            // REAPPLY
            $migration = require base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_24_000001_create_front_desk_checkout_housekeeping_handoffs_table.php');
            $migration->up();
            $this->assertTrue(Schema::hasTable('front_desk_checkout_housekeeping_handoffs'));

            foreach ($fkNames as $fk) {
                $this->assertTrue($this->constraintExists($fk), "FK {$fk} must exist after REAPPLY.");
            }
            foreach ([
                'fd_chh_idempotency_unique', 'fd_chh_correlation_unique',
                'fd_chh_source_hash_unique', 'fd_chh_execution_unique', 'fd_chh_stay_unique',
            ] as $uq) {
                $this->assertTrue($this->constraintExists($uq), "Unique {$uq} must exist after REAPPLY.");
            }
            foreach ([
                'fd_chh_status_check', 'fd_chh_idempotency_check', 'fd_chh_correlation_check',
                'fd_chh_source_hash_check', 'fd_chh_claim_hash_check', 'fd_chh_attempts_check',
                'fd_chh_error_code_check', 'fd_chh_claim_timing_check', 'fd_chh_state_shape_check',
            ] as $chk) {
                $this->assertTrue($this->constraintExists($chk), "CHECK {$chk} must exist after REAPPLY.");
            }
            foreach ([
                'fd_chh_property_id_idx', 'fd_chh_stay_id_idx', 'fd_chh_reservation_id_idx',
                'fd_chh_business_date_id_idx', 'fd_chh_delivery_status_idx', 'fd_chh_available_at_idx',
                'fd_chh_claim_expires_at_idx', 'fd_chh_occurred_at_idx', 'fd_chh_created_at_idx',
                'fd_chh_claimable_idx',
            ] as $idx) {
                $this->assertTrue($this->indexExists($idx), "Index {$idx} must exist after REAPPLY.");
            }
            $this->assertTrue($this->triggerExists('fd_chh_check_source'));
            $this->assertTrue($this->triggerExists('fd_chh_enforce_mutation'));
            $this->assertTrue($this->functionExists('fd_chh_check_source_relationship'));
            $this->assertTrue($this->functionExists('fd_chh_enforce_mutation_rules'));

            // Insert after REAPPLY and verify integrity still works
            $handoffId2 = (string) Str::ulid();
            $insertHandoff($handoffId2, 'mig-proof-reapply', 'corr-mig-reapply', $executionId, $stayId);
            $this->assertRawUpdateFails($handoffId2, ['idempotency_key' => 'mutated-after-reapply']);
            $this->assertRawDeleteFails($handoffId2);
            $this->assertFkInsertFails($propertyId, $stayId, $reservationId, $executionId, $bdId, ['property_id' => (string) Str::ulid()]);
            $this->assertRawDeleteReferencedFails('properties', $propertyId);

        } finally {
            $this->switchDatabase($originalDatabase);
            $this->dropDatabase($admin, $database);
        }

        // Verify canonical test database unchanged
        $this->assertSame('ivorq_testing', config('database.connections.pgsql.database'));
        $this->assertSame(
            $mainCount,
            Schema::hasTable('front_desk_checkout_housekeeping_handoffs')
                ? DB::table('front_desk_checkout_housekeeping_handoffs')->count() : 0
        );
    }

    // ── Prerequisites ──────────────────────────────────────────────────────

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
        Schema::create('front_desk_checkout_executions', function ($table): void {
            $table->char('id', 26)->primary(); $table->char('property_id', 26);
            $table->char('front_desk_stay_id', 26); $table->char('reservation_id', 26);
            $table->string('idempotency_key'); $table->string('terminal_stay_status', 50);
            $table->char('front_desk_final_review_id', 26);
            $table->char('property_business_date_id', 26);
            $table->date('business_date');
            $table->string('night_audit_source_status');
            $table->char('night_audit_source_fingerprint', 64);
            $table->string('pms_financial_attestation_status');
            $table->char('pms_financial_attestation_fingerprint', 64);
            $table->string('general_cashier_attestation_status');
            $table->char('general_cashier_attestation_fingerprint', 64);
            $table->char('source_hash', 64);
            $table->timestamp('occurred_at');
            $table->char('created_by', 26);
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

    // ── Assertion helpers ──────────────────────────────────────────────────

    private function assertRawUpdateFails(string $rowId, array $values): void
    {
        try {
            DB::table('front_desk_checkout_housekeeping_handoffs')
                ->where('id', $rowId)->update($values);
            $this->fail('Raw update should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE',
                $e->getMessage()
            );
        }
    }

    private function assertRawDeleteFails(string $rowId): void
    {
        try {
            DB::table('front_desk_checkout_housekeeping_handoffs')
                ->where('id', $rowId)->delete();
            $this->fail('Raw delete should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_DELETE_FORBIDDEN',
                $e->getMessage()
            );
        }
    }

    private function assertFkInsertFails(
        string $propertyId, string $stayId, string $reservationId,
        string $executionId, string $bdId, array $overrides
    ): void {
        $base = [
            'id' => (string) Str::ulid(), 'property_id' => $propertyId,
            'front_desk_stay_id' => $stayId, 'reservation_id' => $reservationId,
            'checkout_execution_id' => $executionId, 'property_business_date_id' => $bdId,
            'business_date' => '2026-07-24',
            'idempotency_key' => 'fk-' . Str::ulid(),
            'correlation_key' => 'corr-fk-' . Str::ulid(),
            'source_hash' => hash('sha256', 'fk-src-' . Str::ulid()),
            'delivery_status' => 'PENDING', 'attempts' => 0,
            'available_at' => now(), 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];
        $payload = array_merge($base, $overrides);
        try {
            DB::table('front_desk_checkout_housekeeping_handoffs')->insert($payload);
            $this->fail('Raw insert with bad FK should have failed: ' . json_encode($overrides));
        } catch (QueryException $e) {
            $msg = $e->getMessage();
            $this->assertTrue(
                str_contains($msg, 'violates') || str_contains($msg, 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH'),
                "Expected FK violation or source mismatch, got: {$msg}"
            );
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

    /**
     * @param array<string, mixed> $extraOverrides
     */
    private function assertRawInsertKeyFails(
        string $propertyId, string $stayId, string $reservationId,
        string $executionId, string $bdId,
        string $idempotencyKey, string $correlationKey,
        string $expectedConstraint,
        array $extraOverrides = []
    ): void {
        $base = [
            'id' => (string) Str::ulid(), 'property_id' => $propertyId,
            'front_desk_stay_id' => $stayId, 'reservation_id' => $reservationId,
            'checkout_execution_id' => $executionId, 'property_business_date_id' => $bdId,
            'business_date' => '2026-07-24',
            'idempotency_key' => $idempotencyKey,
            'correlation_key' => $correlationKey,
            'source_hash' => hash('sha256', 'ck-' . Str::ulid()),
            'delivery_status' => 'PENDING', 'attempts' => 0,
            'available_at' => now(), 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];
        $payload = array_merge($base, $extraOverrides);
        try {
            DB::table('front_desk_checkout_housekeeping_handoffs')->insert($payload);
            $this->fail("Raw insert with bad key should have failed: {$idempotencyKey} / {$correlationKey}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($expectedConstraint, $e->getMessage());
        }
    }

    private function assertRawInsertSourceMismatchFails(
        string $propertyId, string $stayId, string $reservationId,
        string $executionId, string $bdId
    ): void {
        try {
            DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
                'id' => (string) Str::ulid(), 'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId, 'reservation_id' => $reservationId,
                'checkout_execution_id' => $executionId, 'property_business_date_id' => $bdId,
                'business_date' => '2026-07-24',
                'idempotency_key' => 'src-mis-' . Str::ulid(),
                'correlation_key' => 'corr-src-mis-' . Str::ulid(),
                'source_hash' => hash('sha256', 'src-mis-' . Str::ulid()),
                'delivery_status' => 'PENDING', 'attempts' => 0,
                'available_at' => now(), 'occurred_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('Source mismatch insert should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH',
                $e->getMessage()
            );
        }
    }

    // ── PostgreSQL introspection helpers ───────────────────────────────────

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
        return DB::table('pg_trigger')
            ->where('tgname', $name)
            ->where('tgisinternal', false)
            ->exists();
    }

    private function functionExists(string $name): bool
    {
        return DB::table('pg_proc')->where('proname', $name)->exists();
    }

    // ── Disposable database helpers ────────────────────────────────────────

    private function adminPdo(): PDO
    {
        $config = config('database.connections.pgsql');
        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
            $config['username'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function createDatabase(PDO $pdo, string $database): void
    {
        $pdo->exec('CREATE DATABASE ' . $this->quoteIdentifier($database));
    }

    private function dropDatabase(PDO $pdo, string $database): void
    {
        $this->assertStringStartsWith(self::PREFIX, $database);
        $pdo->prepare(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()'
        )->execute([$database]);
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
