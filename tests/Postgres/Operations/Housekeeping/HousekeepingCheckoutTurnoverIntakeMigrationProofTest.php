<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Tests\PostgresTestCase;

class HousekeepingCheckoutTurnoverIntakeMigrationProofTest extends PostgresTestCase
{
    private const PREFIX = 'ivorq_testing_hk_p11_migration_';

    private const P11_MIGRATION = 'Modules/Operations/Housekeeping/database/migrations/2026_07_30_000001_create_housekeeping_checkout_turnover_intakes_table.php';

    private const PREDECESSOR_MIGRATIONS = [
        'Modules/Foundation/Property/database/migrations/2026_06_03_000002_create_properties_table.php',
        'Modules/Foundation/User/database/migrations/2026_06_03_000007_create_users_table.php',
        'database/migrations/2026_06_21_000001_create_property_business_dates_table.php',
        'database/migrations/2026_06_21_213513_correct_property_business_dates_check_constraint.php',
        'database/migrations/2026_07_16_000001_add_bd_a1_timezone_snapshot_and_immutability_to_property_business_dates.php',
        'Modules/Operations/Housekeeping/database/migrations/2026_06_04_000016_create_rooms_table.php',
        'Modules/Operations/Housekeeping/database/migrations/2026_06_04_000018_create_cleaning_tasks_table.php',
        'Modules/Operations/Housekeeping/database/migrations/2026_07_08_000030_create_housekeeping_room_readiness_transitions_table.php',
        'Modules/Operations/PMS/database/migrations/2026_06_05_000032_create_guests_table.php',
        'Modules/Operations/PMS/database/migrations/2026_06_05_000034_create_reservations_table.php',
        'Modules/Operations/FrontDesk/database/migrations/2026_07_08_000020_create_front_desk_stays_and_room_assignments_table.php',
        'Modules/Operations/FrontDesk/database/migrations/2026_07_10_000031_create_front_desk_departure_checkout_final_reviews_table.php',
        'Modules/Operations/FrontDesk/database/migrations/2026_07_23_000001_create_front_desk_checkout_executions_table.php',
        'Modules/Operations/FrontDesk/database/migrations/2026_07_24_000001_create_front_desk_checkout_housekeeping_handoffs_table.php',
    ];

    /** @var list<string> */
    private const EXPECTED_COLUMNS = [
        'id',
        'property_id',
        'front_desk_checkout_housekeeping_handoff_id',
        'checkout_execution_id',
        'front_desk_stay_id',
        'reservation_id',
        'room_id',
        'property_business_date_id',
        'business_date',
        'cleaning_task_id',
        'room_readiness_transition_id',
        'handoff_source_hash',
        'checkout_execution_source_hash',
        'source_hash',
        'room_readiness_before',
        'room_readiness_after',
        'cleanliness_before',
        'cleanliness_after',
        'consumer_identity',
        'occurred_at',
        'created_at',
    ];

    /** @var list<string> */
    private const EXPECTED_CONSTRAINTS = [
        'housekeeping_checkout_turnover_intakes_pkey',
        'hk_cti_property_fk',
        'hk_cti_handoff_fk',
        'hk_cti_execution_fk',
        'hk_cti_stay_fk',
        'hk_cti_reservation_fk',
        'hk_cti_room_fk',
        'hk_cti_business_date_fk',
        'hk_cti_cleaning_task_fk',
        'hk_cti_transition_fk',
        'hk_cti_handoff_unique',
        'hk_cti_execution_unique',
        'hk_cti_cleaning_task_unique',
        'hk_cti_transition_unique',
        'hk_cti_source_hash_unique',
        'hk_cti_handoff_hash_check',
        'hk_cti_execution_hash_check',
        'hk_cti_source_hash_check',
        'hk_cti_consumer_identity_check',
        'hk_cti_state_shape_check',
    ];

    /** @var list<string> */
    private const EXPECTED_INDEXES = [
        'housekeeping_checkout_turnover_intakes_pkey',
        'hk_cti_handoff_unique',
        'hk_cti_execution_unique',
        'hk_cti_cleaning_task_unique',
        'hk_cti_transition_unique',
        'hk_cti_source_hash_unique',
        'hk_cti_room_idx',
        'hk_cti_business_date_idx',
        'hk_cti_created_at_idx',
    ];

    /** @var list<string> */
    private const EXPECTED_FUNCTIONS = [
        'hk_cti_no_update',
        'hk_cti_no_delete',
        'hk_cti_check_source_relationship',
    ];

    /** @var list<string> */
    private const EXPECTED_TRIGGERS = [
        'hk_cti_no_update_trigger',
        'hk_cti_no_delete_trigger',
        'hk_cti_source_relationship_trigger',
    ];

    /** @var list<string> */
    private const PREDECESSOR_TABLES = [
        'properties',
        'property_business_dates',
        'front_desk_checkout_executions',
        'front_desk_checkout_housekeeping_handoffs',
        'cleaning_tasks',
        'housekeeping_room_readiness_transitions',
    ];

    /** @var list<string> */
    private const PREDECESSOR_FUNCTIONS = [
        'property_business_dates_bd_a1_foundation_guard',
        'hk_readiness_tr_no_update',
        'hk_readiness_tr_no_delete',
        'fd_ce_block_mutation',
        'fd_chh_check_source_relationship',
        'fd_chh_enforce_mutation_rules',
    ];

    /** @var list<string> */
    private const PREDECESSOR_TRIGGERS = [
        'trg_property_business_dates_bd_a1_foundation_guard',
        'hk_readiness_transitions_no_update',
        'hk_readiness_transitions_no_delete',
        'fd_ce_block_update',
        'fd_ce_block_delete',
        'fd_chh_check_source',
        'fd_chh_enforce_mutation',
    ];

    public function test_disposable_database_up_down_reapply_and_malformed_direct_sql_matrix(): void
    {
        $originalDatabase = config('database.connections.pgsql.database');
        $database = self::PREFIX . strtolower((string) Str::ulid());
        $admin = $this->adminPdo();
        $this->assertStringStartsWith(self::PREFIX, $database);
        $this->assertNotSame($originalDatabase, $database);

        $this->createDatabase($admin, $database);

        try {
            $this->switchDatabase($database);
            $this->createSupportDependencyTables();
            $this->runMigrations(self::PREDECESSOR_MIGRATIONS);
            $this->assertPredecessorStateBeforePackage11Up();

            $this->runMigrations([self::P11_MIGRATION]);
            $this->assertPackage11ObjectsExistExactlyOnce();

            $validGraph = $this->createValidSourceGraph();
            $validPayload = $this->intakePayload($validGraph);
            $this->rawInsertIntake($validPayload);
            $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->where('id', $validPayload['id'])->count());

            $this->assertMalformedDirectSqlMatrix();

            $this->rollbackPackage11();
            $this->assertPackage11ObjectsRemoved();
            $this->assertPredecessorStateAfterPackage11Down();

            $this->runMigrations([self::P11_MIGRATION]);
            $this->assertPackage11ObjectsExistExactlyOnce();

            $reapplyGraph = $this->createValidSourceGraph();
            $reapplyPayload = $this->intakePayload($reapplyGraph);
            $this->rawInsertIntake($reapplyPayload);
            $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->where('id', $reapplyPayload['id'])->count());
        } finally {
            $this->switchDatabase($originalDatabase);
            $this->dropDatabase($admin, $database);
        }

        $this->assertSame($originalDatabase, config('database.connections.pgsql.database'));
        $this->assertSame(0, $this->databaseCount($admin, $database));
        $this->assertSame(0, $this->databaseCountLike($admin, self::PREFIX));
    }

    private function assertPredecessorStateBeforePackage11Up(): void
    {
        foreach (self::PREDECESSOR_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Predecessor table {$table} must exist before Package 11 UP.");
        }

        foreach (self::PREDECESSOR_FUNCTIONS as $function) {
            $this->assertSame(1, $this->functionCount($function), "Predecessor function {$function} must exist exactly once.");
        }

        foreach (self::PREDECESSOR_TRIGGERS as $trigger) {
            $this->assertSame(1, $this->triggerCount($trigger), "Predecessor trigger {$trigger} must exist exactly once.");
        }

        $this->assertFalse(Schema::hasTable('housekeeping_checkout_turnover_intakes'));
        foreach (self::EXPECTED_FUNCTIONS as $function) {
            $this->assertSame(0, $this->functionCount($function), "Package 11 function {$function} must not exist before UP.");
        }
        foreach (self::EXPECTED_TRIGGERS as $trigger) {
            $this->assertSame(0, $this->triggerCount($trigger), "Package 11 trigger {$trigger} must not exist before UP.");
        }
    }

    private function assertPackage11ObjectsExistExactlyOnce(): void
    {
        $this->assertSame(1, $this->tableCount('housekeeping_checkout_turnover_intakes'));

        foreach (self::EXPECTED_COLUMNS as $column) {
            $this->assertSame(1, $this->columnCount('housekeeping_checkout_turnover_intakes', $column), "Column {$column} must exist exactly once.");
        }

        foreach (self::EXPECTED_CONSTRAINTS as $constraint) {
            $this->assertSame(1, $this->constraintCount($constraint), "Constraint {$constraint} must exist exactly once.");
        }

        foreach (self::EXPECTED_INDEXES as $index) {
            $this->assertSame(1, $this->indexCount($index), "Index {$index} must exist exactly once.");
        }

        foreach (self::EXPECTED_FUNCTIONS as $function) {
            $this->assertSame(1, $this->functionCount($function), "Function {$function} must exist exactly once.");
        }

        foreach (self::EXPECTED_TRIGGERS as $trigger) {
            $this->assertSame(1, $this->triggerCount($trigger), "Trigger {$trigger} must exist exactly once.");
        }
    }

    private function assertPackage11ObjectsRemoved(): void
    {
        $this->assertSame(0, $this->tableCount('housekeeping_checkout_turnover_intakes'));

        foreach (self::EXPECTED_CONSTRAINTS as $constraint) {
            $this->assertSame(0, $this->constraintCount($constraint), "Package 11 constraint {$constraint} must be removed after DOWN.");
        }

        foreach (self::EXPECTED_INDEXES as $index) {
            $this->assertSame(0, $this->indexCount($index), "Package 11 index {$index} must be removed after DOWN.");
        }

        foreach (self::EXPECTED_FUNCTIONS as $function) {
            $this->assertSame(0, $this->functionCount($function), "Package 11 function {$function} must be removed after DOWN.");
        }

        foreach (self::EXPECTED_TRIGGERS as $trigger) {
            $this->assertSame(0, $this->triggerCount($trigger), "Package 11 trigger {$trigger} must be removed after DOWN.");
        }
    }

    private function assertPredecessorStateAfterPackage11Down(): void
    {
        foreach (self::PREDECESSOR_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Predecessor table {$table} must remain after Package 11 DOWN.");
        }

        foreach (self::PREDECESSOR_FUNCTIONS as $function) {
            $this->assertSame(1, $this->functionCount($function), "Predecessor function {$function} must remain exactly once after DOWN.");
        }

        foreach (self::PREDECESSOR_TRIGGERS as $trigger) {
            $this->assertSame(1, $this->triggerCount($trigger), "Predecessor trigger {$trigger} must remain exactly once after DOWN.");
        }
    }

    private function assertMalformedDirectSqlMatrix(): void
    {
        $cases = [
            'handoff relationship mismatch' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    $payload['front_desk_stay_id'] = $this->createValidSourceGraph()['front_desk_stay_id'];

                    return $payload;
                },
            ],
            'checkout execution mismatch' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    $payload['checkout_execution_id'] = $this->createValidSourceGraph()['checkout_execution_id'];

                    return $payload;
                },
            ],
            'reservation mismatch' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    $payload['reservation_id'] = $this->createValidSourceGraph()['reservation_id'];

                    return $payload;
                },
            ],
            'Property Business Date ID mismatch' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    $payload['property_business_date_id'] = $this->createClosedBusinessDate($graph['property_id'], '2026-08-01');

                    return $payload;
                },
            ],
            'business_date mismatch' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => fn (array $graph, array $payload): array => array_merge($payload, ['business_date' => '2026-08-01']),
            ],
            'handoff source-hash mismatch' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => fn (array $graph, array $payload): array => array_merge($payload, ['handoff_source_hash' => str_repeat('a', 64)]),
            ],
            'checkout execution source-hash mismatch' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => fn (array $graph, array $payload): array => array_merge($payload, ['checkout_execution_source_hash' => str_repeat('b', 64)]),
            ],
            'stay status is not CHECKED_OUT' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    DB::table('front_desk_stays')->where('id', $graph['front_desk_stay_id'])->update(['status' => 'IN_HOUSE']);

                    return $payload;
                },
            ],
            'stay has no authoritative current room' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    DB::table('front_desk_stays')->where('id', $graph['front_desk_stay_id'])->update(['current_room_id' => null]);

                    return $payload;
                },
            ],
            'room is inactive' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_ROOM_UNAVAILABLE',
                'mutate' => function (array $graph, array $payload): array {
                    DB::table('rooms')->where('id', $graph['room_id'])->update(['is_active' => false]);

                    return $payload;
                },
            ],
            'room belongs to another Property' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_ROOM_UNAVAILABLE',
                'mutate' => function (array $graph, array $payload): array {
                    DB::table('rooms')->where('id', $graph['room_id'])->update(['property_id' => $graph['other_property_id']]);

                    return $payload;
                },
            ],
            'CleaningTask belongs to another Property' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    DB::table('cleaning_tasks')->where('id', $graph['cleaning_task_id'])->update(['property_id' => $graph['other_property_id']]);

                    return $payload;
                },
            ],
            'CleaningTask belongs to another room' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    DB::table('cleaning_tasks')->where('id', $graph['cleaning_task_id'])->update(['room_id' => $this->createRoom($graph['property_id'])]);

                    return $payload;
                },
            ],
            'CleaningTask has no room' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    DB::table('cleaning_tasks')->where('id', $graph['cleaning_task_id'])->update(['room_id' => null]);

                    return $payload;
                },
            ],
            'CleaningTask type is not checkout_cleaning' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    DB::table('cleaning_tasks')->where('id', $graph['cleaning_task_id'])->update(['task_type' => 'stayover_cleaning']);

                    return $payload;
                },
            ],
            'CleaningTask type is null' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'mutate' => function (array $graph, array $payload): array {
                    DB::table('cleaning_tasks')->where('id', $graph['cleaning_task_id'])->update(['task_type' => null]);

                    return $payload;
                },
            ],
            'readiness transition belongs to another Property' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'graph' => fn (): array => $this->createValidSourceGraph(['transition_property_id' => 'other']),
            ],
            'readiness transition belongs to another room' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'graph' => fn (): array => $this->createValidSourceGraph(['transition_room_id' => 'alternate']),
            ],
            'transition type is not CHECKOUT_TURNOVER_INTAKE' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'graph' => fn (): array => $this->createValidSourceGraph(['transition_type' => 'START_CLEANING']),
            ],
            'transition source_type is wrong' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'graph' => fn (): array => $this->createValidSourceGraph(['transition_source_type' => 'front_desk_checkout_execution']),
            ],
            'transition source_type is null' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'graph' => fn (): array => $this->createValidSourceGraph(['transition_source_type' => null]),
            ],
            'transition source_id is wrong' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'graph' => fn (): array => $this->createValidSourceGraph(['transition_source_id' => (string) Str::ulid()]),
            ],
            'transition source_id is null' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'graph' => fn (): array => $this->createValidSourceGraph(['transition_source_id' => null]),
            ],
            'transition target is not waiting_cleaning' => [
                'state' => 'P0001',
                'marker' => 'HK_P11_SOURCE_CONFLICT',
                'graph' => fn (): array => $this->createValidSourceGraph(['transition_to_status' => 'cleaning']),
            ],
        ];

        $this->assertCount(24, $cases);

        foreach ($cases as $name => $case) {
            $graph = isset($case['graph']) ? $case['graph']() : $this->createValidSourceGraph();
            $payload = $this->intakePayload($graph);
            if (isset($case['mutate'])) {
                $payload = $case['mutate']($graph, $payload);
            }

            try {
                $this->rawInsertIntake($payload);
                $this->fail("Malformed direct SQL case should have failed: {$name}");
            } catch (QueryException $exception) {
                $this->assertSame($case['state'], $exception->errorInfo[0] ?? null, "{$name} SQLSTATE mismatch.");
                $this->assertStringContainsString($case['marker'], $exception->getMessage(), "{$name} marker mismatch.");
            }

            $this->assertSame(0, DB::table('housekeeping_checkout_turnover_intakes')->where('id', $payload['id'])->count(), "{$name} must leave zero malformed intake rows.");
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function createValidSourceGraph(array $options = []): array
    {
        $companyId = $this->companyId();
        $propertyId = $this->createProperty($companyId);
        $otherPropertyId = $this->createProperty($companyId, 'P11O');
        $actorId = $this->createUser();
        $roomId = $this->createRoom($propertyId);
        $guestId = $this->createGuest($propertyId);
        $reservationId = $this->createReservation($propertyId, $guestId);
        $businessDateId = $this->createOpenBusinessDate($propertyId, $actorId);
        $stayId = (string) Str::ulid();

        DB::table('front_desk_stays')->insert([
            'id' => $stayId,
            'property_id' => $propertyId,
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'status' => 'CHECKED_OUT',
            'current_room_id' => $roomId,
            'current_room_assignment_id' => null,
            'checked_in_at' => '2026-07-30 08:00:00',
            'checked_in_by' => null,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => '2026-07-30 08:00:00',
            'updated_at' => '2026-07-30 08:00:00',
        ]);

        $reviewId = (string) Str::ulid();
        DB::table('front_desk_departure_checkout_final_reviews')->insert([
            'id' => $reviewId,
            'property_id' => $propertyId,
            'front_desk_stay_id' => $stayId,
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'room_id' => $roomId,
            'final_review_status' => 'CHECKOUT_FINAL_REVIEW_READY',
            'final_review_note' => null,
            'occurred_at' => '2026-07-30 09:00:00',
            'created_by' => $actorId,
            'idempotency_key' => 'p11-review-' . Str::ulid(),
            'source_hash' => hash('sha256', 'review-' . $stayId),
            'created_at' => '2026-07-30 09:00:00',
        ]);

        $executionId = (string) Str::ulid();
        $executionSourceHash = hash('sha256', 'execution-' . $executionId);
        DB::table('front_desk_checkout_executions')->insert([
            'id' => $executionId,
            'property_id' => $propertyId,
            'front_desk_stay_id' => $stayId,
            'reservation_id' => $reservationId,
            'idempotency_key' => 'p11-exec-' . Str::ulid(),
            'terminal_stay_status' => 'CHECKED_OUT',
            'front_desk_final_review_id' => $reviewId,
            'property_business_date_id' => $businessDateId,
            'business_date' => '2026-07-30',
            'night_audit_source_status' => 'NA_A2_CLEAR',
            'night_audit_source_fingerprint' => hash('sha256', 'na-' . $executionId),
            'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
            'pms_financial_attestation_fingerprint' => hash('sha256', 'pms-' . $executionId),
            'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
            'general_cashier_attestation_fingerprint' => hash('sha256', 'gc-' . $executionId),
            'source_hash' => $executionSourceHash,
            'occurred_at' => '2026-07-30 09:05:00',
            'created_by' => $actorId,
            'created_at' => '2026-07-30 09:05:00',
        ]);

        $handoffId = (string) Str::ulid();
        $handoffSourceHash = hash('sha256', 'handoff-' . $handoffId);
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $handoffId,
            'property_id' => $propertyId,
            'front_desk_stay_id' => $stayId,
            'reservation_id' => $reservationId,
            'checkout_execution_id' => $executionId,
            'property_business_date_id' => $businessDateId,
            'business_date' => '2026-07-30',
            'idempotency_key' => 'p11-handoff-' . Str::ulid(),
            'correlation_key' => 'p11-correlation-' . Str::ulid(),
            'source_hash' => $handoffSourceHash,
            'delivery_status' => 'CLAIMED',
            'attempts' => 1,
            'available_at' => '2026-07-30 09:05:00',
            'claimed_at' => '2026-07-30 09:06:00',
            'claim_expires_at' => '2026-07-30 09:07:00',
            'claim_token_hash' => hash('sha256', 'claim-' . $handoffId),
            'delivered_at' => null,
            'failed_at' => null,
            'last_error_code' => null,
            'occurred_at' => '2026-07-30 09:06:00',
            'created_at' => '2026-07-30 09:06:00',
            'updated_at' => '2026-07-30 09:06:00',
        ]);

        $cleaningTaskId = (string) Str::ulid();
        DB::table('cleaning_tasks')->insert([
            'id' => $cleaningTaskId,
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'zone_id' => null,
            'task_code' => null,
            'title' => null,
            'task_type' => $options['task_type'] ?? 'checkout_cleaning',
            'status' => 'pending',
            'priority' => 'normal',
            'credits' => 1,
            'scheduled_at' => null,
            'due_date' => null,
            'started_at' => null,
            'completed_at' => null,
            'completed_by' => null,
            'verified_at' => null,
            'sla_minutes_target' => 45,
            'sla_breached' => false,
            'notes' => null,
            'created_by' => null,
            'created_at' => '2026-07-30 09:06:00',
            'updated_at' => '2026-07-30 09:06:00',
            'deleted_at' => null,
        ]);

        $transitionPropertyId = ($options['transition_property_id'] ?? null) === 'other' ? $otherPropertyId : $propertyId;
        $transitionRoomId = ($options['transition_room_id'] ?? null) === 'alternate'
            ? $this->createRoom($propertyId)
            : $roomId;
        $transitionId = (string) Str::ulid();
        DB::table('housekeeping_room_readiness_transitions')->insert([
            'id' => $transitionId,
            'property_id' => $transitionPropertyId,
            'room_id' => $transitionRoomId,
            'from_status' => 'ready_for_sale',
            'to_status' => $options['transition_to_status'] ?? 'waiting_cleaning',
            'transition_type' => $options['transition_type'] ?? 'CHECKOUT_TURNOVER_INTAKE',
            'reason' => null,
            'source_type' => array_key_exists('transition_source_type', $options)
                ? $options['transition_source_type']
                : 'front_desk_checkout_housekeeping_handoff',
            'source_id' => array_key_exists('transition_source_id', $options)
                ? $options['transition_source_id']
                : $handoffId,
            'occurred_at' => '2026-07-30 09:06:00',
            'created_by' => null,
            'idempotency_key' => 'p11-transition-' . Str::ulid(),
            'source_hash' => hash('sha256', 'transition-' . $transitionId),
            'created_at' => '2026-07-30 09:06:00',
        ]);

        return [
            'property_id' => $propertyId,
            'other_property_id' => $otherPropertyId,
            'front_desk_checkout_housekeeping_handoff_id' => $handoffId,
            'checkout_execution_id' => $executionId,
            'front_desk_stay_id' => $stayId,
            'reservation_id' => $reservationId,
            'room_id' => $roomId,
            'property_business_date_id' => $businessDateId,
            'business_date' => '2026-07-30',
            'cleaning_task_id' => $cleaningTaskId,
            'room_readiness_transition_id' => $transitionId,
            'handoff_source_hash' => $handoffSourceHash,
            'checkout_execution_source_hash' => $executionSourceHash,
        ];
    }

    /**
     * @param array<string, string> $graph
     * @return array<string, mixed>
     */
    private function intakePayload(array $graph): array
    {
        $id = (string) Str::ulid();

        return [
            'id' => $id,
            'property_id' => $graph['property_id'],
            'front_desk_checkout_housekeeping_handoff_id' => $graph['front_desk_checkout_housekeeping_handoff_id'],
            'checkout_execution_id' => $graph['checkout_execution_id'],
            'front_desk_stay_id' => $graph['front_desk_stay_id'],
            'reservation_id' => $graph['reservation_id'],
            'room_id' => $graph['room_id'],
            'property_business_date_id' => $graph['property_business_date_id'],
            'business_date' => $graph['business_date'],
            'cleaning_task_id' => $graph['cleaning_task_id'],
            'room_readiness_transition_id' => $graph['room_readiness_transition_id'],
            'handoff_source_hash' => $graph['handoff_source_hash'],
            'checkout_execution_source_hash' => $graph['checkout_execution_source_hash'],
            'source_hash' => hash('sha256', 'intake-' . $id),
            'room_readiness_before' => 'ready_for_sale',
            'room_readiness_after' => 'waiting_cleaning',
            'cleanliness_before' => 'inspected',
            'cleanliness_after' => 'dirty',
            'consumer_identity' => 'housekeeping-checkout-turnover-consumer-v1',
            'occurred_at' => '2026-07-30 09:06:00',
            'created_at' => '2026-07-30 09:06:00',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rawInsertIntake(array $payload): void
    {
        $columns = array_keys($payload);
        $quotedColumns = implode(', ', array_map(fn (string $column): string => '"' . $column . '"', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        DB::insert(
            "INSERT INTO housekeeping_checkout_turnover_intakes ({$quotedColumns}) VALUES ({$placeholders})",
            array_values($payload),
        );
    }

    private function createSupportDependencyTables(): void
    {
        Schema::create('companies', function ($table): void {
            $table->char('id', 26)->primary();
        });
        Schema::create('departments', function ($table): void {
            $table->char('id', 26)->primary();
        });
        Schema::create('positions', function ($table): void {
            $table->char('id', 26)->primary();
        });
        Schema::create('rate_plans', function ($table): void {
            $table->char('id', 26)->primary();
        });
    }

    private function companyId(): string
    {
        $id = (string) Str::ulid();
        DB::table('companies')->insert(['id' => $id]);

        return $id;
    }

    private function createProperty(string $companyId, string $codePrefix = 'P11P'): string
    {
        $id = (string) Str::ulid();
        DB::table('properties')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'name' => 'P11 Migration Property ' . Str::upper(Str::random(5)),
            'slug' => 'p11-migration-property-' . Str::lower(Str::random(10)),
            'code' => $codePrefix . Str::upper(Str::random(4)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
            'created_at' => '2026-07-30 08:00:00',
            'updated_at' => '2026-07-30 08:00:00',
        ]);

        return $id;
    }

    private function createUser(): string
    {
        $id = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $id,
            'is_system_admin' => false,
            'name' => 'P11 Migration User',
            'email' => 'p11-migration-' . Str::lower(Str::random(10)) . '@example.test',
            'password' => 'not-a-secret-test-hash',
            'is_active' => true,
            'created_at' => '2026-07-30 08:00:00',
            'updated_at' => '2026-07-30 08:00:00',
        ]);

        return $id;
    }

    private function createRoom(string $propertyId): string
    {
        $id = (string) Str::ulid();
        DB::table('rooms')->insert([
            'id' => $id,
            'property_id' => $propertyId,
            'room_number' => 'P11-' . Str::upper(Str::random(6)),
            'room_type' => 'deluxe',
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_sale',
            'occupancy_status' => 'vacant',
            'is_active' => true,
            'is_vip' => false,
            'credits' => 1,
            'created_at' => '2026-07-30 08:00:00',
            'updated_at' => '2026-07-30 08:00:00',
        ]);

        return $id;
    }

    private function createGuest(string $propertyId): string
    {
        $id = (string) Str::ulid();
        DB::table('guests')->insert([
            'id' => $id,
            'property_id' => $propertyId,
            'guest_code' => 'P11G' . Str::upper(Str::random(6)),
            'full_name' => 'P11 Migration Guest',
            'guest_type' => 'individual',
            'created_at' => '2026-07-30 08:00:00',
            'updated_at' => '2026-07-30 08:00:00',
        ]);

        return $id;
    }

    private function createReservation(string $propertyId, string $guestId): string
    {
        $id = (string) Str::ulid();
        DB::table('reservations')->insert([
            'id' => $id,
            'property_id' => $propertyId,
            'reservation_number' => 'P11R' . Str::upper(Str::random(6)),
            'primary_guest_id' => $guestId,
            'adults' => 1,
            'children' => 0,
            'arrival_date' => '2026-07-29',
            'departure_date' => '2026-07-30',
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => 'checked_in',
            'reserved_room_type' => 'deluxe',
            'created_at' => '2026-07-30 08:00:00',
            'updated_at' => '2026-07-30 08:00:00',
        ]);

        return $id;
    }

    private function createOpenBusinessDate(string $propertyId, string $actorId): string
    {
        $id = (string) Str::ulid();
        DB::table('property_business_dates')->insert([
            'id' => $id,
            'property_id' => $propertyId,
            'business_date' => '2026-07-30',
            'status' => 'Open',
            'is_open' => true,
            'opened_by' => $actorId,
            'opened_at' => '2026-07-30 06:00:00',
            'timezone_snapshot' => 'UTC',
            'created_at' => '2026-07-30 06:00:00',
            'updated_at' => '2026-07-30 06:00:00',
        ]);

        return $id;
    }

    private function createClosedBusinessDate(string $propertyId, string $date): string
    {
        $id = (string) Str::ulid();
        DB::table('property_business_dates')->insert([
            'id' => $id,
            'property_id' => $propertyId,
            'business_date' => $date,
            'status' => 'Closed',
            'is_open' => null,
            'timezone_snapshot' => 'UTC',
            'created_at' => '2026-07-30 06:00:00',
            'updated_at' => '2026-07-30 06:00:00',
        ]);

        return $id;
    }

    /**
     * @param list<string> $relativePaths
     * @return list<string>
     */
    private function runMigrations(array $relativePaths): array
    {
        $migrator = app('migrator');
        $migrator->setConnection('pgsql');
        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        return $migrator->run(
            array_map(fn (string $path): string => base_path($path), $relativePaths),
            ['pretend' => false],
        );
    }

    private function rollbackPackage11(): void
    {
        $migrator = app('migrator');
        $migrator->setConnection('pgsql');
        $rolledBack = $migrator->rollback([base_path(self::P11_MIGRATION)], ['pretend' => false, 'step' => 1]);

        $this->assertCount(1, $rolledBack);
        $this->assertSame(base_path(self::P11_MIGRATION), $rolledBack[0]);
    }

    private function tableCount(string $name): int
    {
        return (int) DB::table('pg_class')
            ->where('relname', $name)
            ->whereIn('relkind', ['r', 'p'])
            ->count();
    }

    private function columnCount(string $table, string $column): int
    {
        return (int) DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->count();
    }

    private function constraintCount(string $name): int
    {
        return (int) DB::table('pg_constraint')->where('conname', $name)->count();
    }

    private function indexCount(string $name): int
    {
        return (int) DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('indexname', $name)
            ->count();
    }

    private function functionCount(string $name): int
    {
        return (int) DB::table('pg_proc')
            ->join('pg_namespace', 'pg_namespace.oid', '=', 'pg_proc.pronamespace')
            ->where('pg_namespace.nspname', 'public')
            ->where('pg_proc.proname', $name)
            ->count();
    }

    private function triggerCount(string $name): int
    {
        return (int) DB::table('pg_trigger')
            ->where('tgname', $name)
            ->where('tgisinternal', false)
            ->count();
    }

    private function adminPdo(): PDO
    {
        $config = config('database.connections.pgsql');

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function createDatabase(PDO $pdo, string $database): void
    {
        $pdo->exec('CREATE DATABASE ' . $this->quoteIdentifier($database));
    }

    private function dropDatabase(PDO $pdo, string $database): void
    {
        $this->assertStringStartsWith(self::PREFIX, $database);
        DB::disconnect('pgsql');
        DB::purge('pgsql');
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

    private function databaseCount(PDO $pdo, string $database): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM pg_database WHERE datname = ?');
        $statement->execute([$database]);

        return (int) $statement->fetchColumn();
    }

    private function databaseCountLike(PDO $pdo, string $prefix): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM pg_database WHERE datname LIKE ?');
        $statement->execute([$prefix . '%']);

        return (int) $statement->fetchColumn();
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $identifier)) {
            throw new \RuntimeException('Unsafe disposable database identifier.');
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
