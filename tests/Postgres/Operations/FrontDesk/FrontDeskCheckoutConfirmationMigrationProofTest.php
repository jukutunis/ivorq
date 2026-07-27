<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Tests\PostgresTestCase;

class FrontDeskCheckoutConfirmationMigrationProofTest extends PostgresTestCase
{
    private const PREFIX = 'ivorq_testing_p8_csc_migration_';

    public function test_package8_migration_up_down_reapply_and_source_relationships_on_disposable_database(): void
    {
        $originalDatabase = config('database.connections.pgsql.database');
        $database = self::PREFIX . strtolower((string) Str::ulid());

        $this->assertStringStartsWith(self::PREFIX, $database);
        $this->assertNotSame('ivorq_testing', $database);

        $admin = $this->adminPdo();
        $this->createDatabase($admin, $database);

        try {
            $this->switchDatabase($database);
            $this->createPrerequisites();

            $fdC1 = require base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_23_000001_create_front_desk_checkout_executions_table.php');
            $p8Evidence = require base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_27_000001_create_checkout_sensitive_confirmation_evidence_tables.php');
            $p8FdC1 = require base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_27_000002_add_package8_confirmation_evidence_to_front_desk_checkout_executions.php');

            $fdC1->up();
            $p8Evidence->up();
            $p8FdC1->up();

            $this->assertPackage8SchemaRestored();

            $fixture = $this->fixture();
            $historicalExecutionId = $this->insertCheckoutExecution($fixture, 'historical-null-p8', null, [
                'front_desk_stay_id' => $fixture['historical_stay_id'],
            ]);
            $this->assertDatabaseHas('front_desk_checkout_executions', [
                'id' => $historicalExecutionId,
                'checkout_confirmation_consumption_id' => null,
            ]);

            $this->assertQueryFails(
                fn () => $this->insertIssuance($fixture, 'bad-property-company', ['company_id' => $fixture['other_company_id']]),
                'P8_CHECKOUT_CONFIRMATION_ISSUANCE_SOURCE_MISMATCH'
            );
            $this->assertQueryFails(
                fn () => $this->insertIssuance($fixture, 'inactive-company', ['company_id' => $fixture['inactive_company_id']]),
                'P8_CHECKOUT_CONFIRMATION_ISSUANCE_SOURCE_MISMATCH'
            );
            $this->assertQueryFails(
                fn () => $this->insertIssuance($fixture, 'inactive-property', ['property_id' => $fixture['inactive_property_id']]),
                'P8_CHECKOUT_CONFIRMATION_ISSUANCE_SOURCE_MISMATCH'
            );
            $this->assertQueryFails(
                fn () => $this->insertIssuance($fixture, 'inactive-actor', ['actor_id' => $fixture['inactive_actor_id']]),
                'P8_CHECKOUT_CONFIRMATION_ISSUANCE_SOURCE_MISMATCH'
            );
            $this->assertQueryFails(
                fn () => $this->insertIssuance($fixture, 'cross-stay', ['front_desk_stay_id' => $fixture['other_stay_id']]),
                'P8_CHECKOUT_CONFIRMATION_ISSUANCE_SOURCE_MISMATCH'
            );

            $issuanceId = $this->insertIssuance($fixture, 'checkout-identity-1');
            $this->assertQueryFails(
                fn () => $this->insertConsumption($issuanceId, ['property_id' => $fixture['other_property_id']]),
                'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_CONTEXT_MISMATCH'
            );

            $expiredIssuanceId = $this->insertIssuance(
                $fixture,
                'expired-consumption',
                [],
                now()->subMinutes(20),
                now()->subMinute()
            );
            $this->assertQueryFails(
                fn () => $this->insertConsumption($expiredIssuanceId),
                'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_EXPIRED'
            );

            $consumptionId = $this->insertConsumption($issuanceId);
            $this->assertQueryFails(
                fn () => $this->insertConsumption($issuanceId),
                'p8_csc_consume_issuance_unique'
            );

            $sameCheckoutIssuanceId = $this->insertIssuance($fixture, 'checkout-identity-1');
            $this->assertQueryFails(
                fn () => $this->insertConsumption($sameCheckoutIssuanceId),
                'p8_csc_consume_checkout_unique'
            );

            $consumption = DB::table('checkout_sensitive_confirmation_consumptions')->where('id', $consumptionId)->first();
            $issuance = DB::table('checkout_sensitive_confirmation_issuances')->where('id', $issuanceId)->first();
            $this->insertCheckoutExecution($fixture, 'with-confirmation', ['consumption' => $consumption, 'issuance' => $issuance]);
            $this->assertQueryFails(
                fn () => $this->insertCheckoutExecution($fixture, 'mismatched-confirmation', [
                    'consumption' => $consumption,
                    'issuance' => $issuance,
                    'front_desk_stay_id' => $fixture['mismatched_execution_stay_id'],
                    'checkout_confirmation_fingerprint' => str_repeat('a', 64),
                ]),
                'P8_CHECKOUT_EXECUTION_CONFIRMATION_SOURCE_MISMATCH'
            );

            $this->assertQueryFails(
                fn () => DB::table('checkout_sensitive_confirmation_issuances')->where('id', $issuanceId)->update(['intent' => 'mutated']),
                'P8_CHECKOUT_CONFIRMATION_ISSUANCE_IMMUTABLE'
            );
            $this->assertQueryFails(
                fn () => DB::table('checkout_sensitive_confirmation_issuances')->where('id', $issuanceId)->delete(),
                'P8_CHECKOUT_CONFIRMATION_ISSUANCE_IMMUTABLE'
            );
            $this->assertQueryFails(
                fn () => DB::table('checkout_sensitive_confirmation_consumptions')->where('id', $consumptionId)->update(['checkout_idempotency_key' => 'mutated']),
                'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_IMMUTABLE'
            );
            $this->assertQueryFails(
                fn () => DB::table('checkout_sensitive_confirmation_consumptions')->where('id', $consumptionId)->delete(),
                'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_IMMUTABLE'
            );

            $p8FdC1->down();
            $p8Evidence->down();
            $this->assertFalse(Schema::hasTable('checkout_sensitive_confirmation_issuances'));
            $this->assertFalse(Schema::hasTable('checkout_sensitive_confirmation_consumptions'));
            $this->assertFalse(Schema::hasColumn('front_desk_checkout_executions', 'checkout_confirmation_consumption_id'));
            foreach ([
                'p8_csc_issue_source_guard',
                'p8_csc_issue_block_mutation',
                'p8_csc_consume_guard',
                'p8_csc_consume_block_mutation',
                'fd_ce_p8_confirmation_source_guard',
            ] as $function) {
                $this->assertFalse($this->functionExists($function), "Function {$function} must be removed after DOWN.");
            }

            $this->assertSame(2, DB::table('front_desk_checkout_executions')->count());
            $this->insertCheckoutExecution($fixture, 'post-down-historical', null, [
                'front_desk_stay_id' => $fixture['post_down_stay_id'],
            ]);
            $this->assertFalse(Schema::hasColumn('front_desk_checkout_executions', 'checkout_confirmation_fingerprint'));

            $p8Evidence = require base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_27_000001_create_checkout_sensitive_confirmation_evidence_tables.php');
            $p8FdC1 = require base_path('Modules/Operations/FrontDesk/database/migrations/2026_07_27_000002_add_package8_confirmation_evidence_to_front_desk_checkout_executions.php');
            $p8Evidence->up();
            $p8FdC1->up();
            $this->assertPackage8SchemaRestored();
            $reappliedIssuanceId = $this->insertIssuance($fixture, 'reapplied-key');
            $this->assertQueryFails(
                fn () => $this->insertConsumption($reappliedIssuanceId, ['front_desk_stay_id' => $fixture['other_stay_id']]),
                'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_CONTEXT_MISMATCH'
            );
        } finally {
            $this->switchDatabase($originalDatabase);
            $this->dropDatabase($admin, $database);
        }

        $this->assertSame('ivorq_testing', config('database.connections.pgsql.database'));
    }

    private function createPrerequisites(): void
    {
        Schema::create('companies', function ($table): void {
            $table->char('id', 26)->primary();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('properties', function ($table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26);
            $table->boolean('is_active')->default(true);
        });
        Schema::create('users', function ($table): void {
            $table->char('id', 26)->primary();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('front_desk_stays', function ($table): void {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);
            $table->string('status');
            $table->char('created_by', 26);
            $table->char('updated_by', 26);
            $table->timestamps();
        });
        Schema::create('reservations', function ($table): void {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('primary_guest_id', 26);
            $table->string('reservation_number');
            $table->string('status');
        });
        Schema::create('front_desk_departure_checkout_final_reviews', function ($table): void {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('front_desk_stay_id', 26);
            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);
            $table->string('final_review_status', 50);
            $table->timestamp('occurred_at');
            $table->char('created_by', 26);
            $table->string('idempotency_key');
            $table->char('source_hash', 64);
            $table->timestamp('created_at');
        });
        Schema::create('property_business_dates', function ($table): void {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->date('business_date');
            $table->string('timezone_snapshot')->nullable();
            $table->string('status');
            $table->boolean('is_open')->nullable();
            $table->char('opened_by', 26)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, string>
     */
    private function fixture(): array
    {
        $ids = [
            'company_id' => (string) Str::ulid(),
            'other_company_id' => (string) Str::ulid(),
            'inactive_company_id' => (string) Str::ulid(),
            'property_id' => (string) Str::ulid(),
            'other_property_id' => (string) Str::ulid(),
            'inactive_property_id' => (string) Str::ulid(),
            'actor_id' => (string) Str::ulid(),
            'inactive_actor_id' => (string) Str::ulid(),
            'stay_id' => (string) Str::ulid(),
            'historical_stay_id' => (string) Str::ulid(),
            'mismatched_execution_stay_id' => (string) Str::ulid(),
            'post_down_stay_id' => (string) Str::ulid(),
            'other_stay_id' => (string) Str::ulid(),
            'reservation_id' => (string) Str::ulid(),
            'other_reservation_id' => (string) Str::ulid(),
            'review_id' => (string) Str::ulid(),
            'business_date_id' => (string) Str::ulid(),
        ];

        DB::table('companies')->insert([
            ['id' => $ids['company_id'], 'is_active' => true],
            ['id' => $ids['other_company_id'], 'is_active' => true],
            ['id' => $ids['inactive_company_id'], 'is_active' => false],
        ]);
        DB::table('properties')->insert([
            ['id' => $ids['property_id'], 'company_id' => $ids['company_id'], 'is_active' => true],
            ['id' => $ids['other_property_id'], 'company_id' => $ids['other_company_id'], 'is_active' => true],
            ['id' => $ids['inactive_property_id'], 'company_id' => $ids['company_id'], 'is_active' => false],
        ]);
        DB::table('users')->insert([
            ['id' => $ids['actor_id'], 'is_active' => true],
            ['id' => $ids['inactive_actor_id'], 'is_active' => false],
        ]);
        DB::table('reservations')->insert([
            ['id' => $ids['reservation_id'], 'property_id' => $ids['property_id'], 'primary_guest_id' => (string) Str::ulid(), 'reservation_number' => 'P8-' . Str::upper(Str::random(6)), 'status' => 'checked_in'],
            ['id' => $ids['other_reservation_id'], 'property_id' => $ids['other_property_id'], 'primary_guest_id' => (string) Str::ulid(), 'reservation_number' => 'P8O-' . Str::upper(Str::random(6)), 'status' => 'checked_in'],
        ]);
        DB::table('front_desk_stays')->insert([
            ['id' => $ids['stay_id'], 'property_id' => $ids['property_id'], 'reservation_id' => $ids['reservation_id'], 'guest_id' => (string) Str::ulid(), 'status' => 'IN_HOUSE', 'created_by' => $ids['actor_id'], 'updated_by' => $ids['actor_id'], 'created_at' => now(), 'updated_at' => now()],
            ['id' => $ids['historical_stay_id'], 'property_id' => $ids['property_id'], 'reservation_id' => $ids['reservation_id'], 'guest_id' => (string) Str::ulid(), 'status' => 'IN_HOUSE', 'created_by' => $ids['actor_id'], 'updated_by' => $ids['actor_id'], 'created_at' => now(), 'updated_at' => now()],
            ['id' => $ids['mismatched_execution_stay_id'], 'property_id' => $ids['property_id'], 'reservation_id' => $ids['reservation_id'], 'guest_id' => (string) Str::ulid(), 'status' => 'IN_HOUSE', 'created_by' => $ids['actor_id'], 'updated_by' => $ids['actor_id'], 'created_at' => now(), 'updated_at' => now()],
            ['id' => $ids['post_down_stay_id'], 'property_id' => $ids['property_id'], 'reservation_id' => $ids['reservation_id'], 'guest_id' => (string) Str::ulid(), 'status' => 'IN_HOUSE', 'created_by' => $ids['actor_id'], 'updated_by' => $ids['actor_id'], 'created_at' => now(), 'updated_at' => now()],
            ['id' => $ids['other_stay_id'], 'property_id' => $ids['other_property_id'], 'reservation_id' => $ids['other_reservation_id'], 'guest_id' => (string) Str::ulid(), 'status' => 'IN_HOUSE', 'created_by' => $ids['actor_id'], 'updated_by' => $ids['actor_id'], 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('front_desk_departure_checkout_final_reviews')->insert([
            'id' => $ids['review_id'],
            'property_id' => $ids['property_id'],
            'front_desk_stay_id' => $ids['stay_id'],
            'reservation_id' => $ids['reservation_id'],
            'guest_id' => (string) Str::ulid(),
            'final_review_status' => 'CHECKOUT_FINAL_REVIEW_READY',
            'occurred_at' => now(),
            'created_by' => $ids['actor_id'],
            'idempotency_key' => 'review-' . Str::ulid(),
            'source_hash' => hash('sha256', 'review-source'),
            'created_at' => now(),
        ]);
        DB::table('property_business_dates')->insert([
            'id' => $ids['business_date_id'],
            'property_id' => $ids['property_id'],
            'business_date' => '2026-07-27',
            'timezone_snapshot' => 'UTC',
            'status' => 'Open',
            'is_open' => true,
            'opened_by' => $ids['actor_id'],
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $ids;
    }

    /**
     * @param array<string, string> $fixture
     * @param array<string, mixed> $overrides
     */
    private function insertIssuance(array $fixture, string $idempotencyKey, array $overrides = [], $confirmedAt = null, $expiresAt = null): string
    {
        $id = (string) Str::ulid();
        $identity = (string) Str::ulid();
        $confirmedAt ??= now();
        $expiresAt ??= now()->addMinutes(15);
        DB::table('checkout_sensitive_confirmation_issuances')->insert(array_merge([
            'id' => $id,
            'confirmation_identity' => $identity,
            'intent' => 'frontdesk-checkout-execution',
            'actor_id' => $fixture['actor_id'],
            'company_id' => $fixture['company_id'],
            'property_id' => $fixture['property_id'],
            'front_desk_stay_id' => $fixture['stay_id'],
            'checkout_idempotency_key' => $idempotencyKey,
            'session_fingerprint' => hash('sha256', 'session-' . $id),
            'confirmation_fingerprint' => hash('sha256', 'confirmation-' . $id),
            'confirmed_at' => $confirmedAt,
            'expires_at' => $expiresAt,
            'created_at' => $confirmedAt,
        ], $overrides));

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertConsumption(string $issuanceId, array $overrides = []): string
    {
        $issuance = DB::table('checkout_sensitive_confirmation_issuances')->where('id', $issuanceId)->first();
        $id = (string) Str::ulid();
        DB::table('checkout_sensitive_confirmation_consumptions')->insert(array_merge([
            'id' => $id,
            'issuance_id' => $issuance->id,
            'confirmation_identity' => $issuance->confirmation_identity,
            'confirmation_fingerprint' => $issuance->confirmation_fingerprint,
            'actor_id' => $issuance->actor_id,
            'company_id' => $issuance->company_id,
            'property_id' => $issuance->property_id,
            'front_desk_stay_id' => $issuance->front_desk_stay_id,
            'checkout_idempotency_key' => $issuance->checkout_idempotency_key,
            'consumed_at' => now(),
            'created_at' => now(),
        ], $overrides));

        return $id;
    }

    /**
     * @param array<string, string> $fixture
     * @param array<string, mixed>|null $confirmation
     */
    private function insertCheckoutExecution(array $fixture, string $key, ?array $confirmation, array $overrides = []): string
    {
        $id = (string) Str::ulid();
        $payload = [
            'id' => $id,
            'property_id' => $fixture['property_id'],
            'front_desk_stay_id' => $fixture['stay_id'],
            'reservation_id' => $fixture['reservation_id'],
            'idempotency_key' => $key,
            'terminal_stay_status' => 'CHECKED_OUT',
            'front_desk_final_review_id' => $fixture['review_id'],
            'property_business_date_id' => $fixture['business_date_id'],
            'business_date' => '2026-07-27',
            'night_audit_source_status' => 'NA_A2_CLEAR',
            'night_audit_source_fingerprint' => hash('sha256', 'na-' . $key),
            'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
            'pms_financial_attestation_fingerprint' => hash('sha256', 'pms-' . $key),
            'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
            'general_cashier_attestation_fingerprint' => hash('sha256', 'gc-' . $key),
            'source_hash' => hash('sha256', 'source-' . $key),
            'occurred_at' => now(),
            'created_by' => $fixture['actor_id'],
            'created_at' => now(),
        ];

        if ($confirmation !== null) {
            $consumption = $confirmation['consumption'];
            $issuance = $confirmation['issuance'];
            $payload = array_merge($payload, [
                'idempotency_key' => $consumption->checkout_idempotency_key,
                'checkout_confirmation_consumption_id' => $consumption->id,
                'checkout_confirmation_fingerprint' => $consumption->confirmation_fingerprint,
                'checkout_confirmed_at' => $issuance->confirmed_at,
                'checkout_confirmation_expires_at' => $issuance->expires_at,
                'checkout_confirmation_consumed_at' => $consumption->consumed_at,
            ], array_diff_key($confirmation, ['consumption' => true, 'issuance' => true]));
        }

        $payload = array_merge($payload, $overrides);

        DB::table('front_desk_checkout_executions')->insert($payload);

        return $id;
    }

    private function assertPackage8SchemaRestored(): void
    {
        $this->assertTrue(Schema::hasTable('checkout_sensitive_confirmation_issuances'));
        $this->assertTrue(Schema::hasTable('checkout_sensitive_confirmation_consumptions'));
        foreach ([
            'p8_csc_issue_identity_unique',
            'p8_csc_issue_fingerprint_unique',
            'p8_csc_issue_context_unique',
            'p8_csc_issue_intent_check',
            'p8_csc_issue_idem_check',
            'p8_csc_issue_session_sha',
            'p8_csc_issue_confirm_sha',
            'p8_csc_issue_time_check',
            'p8_csc_consume_issuance_unique',
            'p8_csc_consume_checkout_unique',
            'p8_csc_consume_issue_context_fk',
            'p8_csc_consume_idem_check',
            'p8_csc_consume_confirm_sha',
            'fd_ce_p8_confirmation_consumption_unique',
            'fd_ce_p8_confirmation_consumption_fk',
            'fd_ce_p8_confirmation_all_or_none',
            'fd_ce_p8_confirmation_fingerprint_sha',
            'fd_ce_p8_confirmation_time_order',
        ] as $constraint) {
            $this->assertTrue($this->constraintExists($constraint), "Missing constraint {$constraint}");
        }
        foreach ([
            'p8_csc_issue_source_guard',
            'p8_csc_issue_no_update',
            'p8_csc_issue_no_delete',
            'p8_csc_consume_insert_guard',
            'p8_csc_consume_no_update',
            'p8_csc_consume_no_delete',
            'fd_ce_p8_confirmation_source_guard',
        ] as $trigger) {
            $this->assertTrue($this->triggerExists($trigger), "Missing trigger {$trigger}");
        }
        foreach ([
            'p8_csc_issue_source_guard',
            'p8_csc_issue_block_mutation',
            'p8_csc_consume_guard',
            'p8_csc_consume_block_mutation',
            'fd_ce_p8_confirmation_source_guard',
        ] as $function) {
            $this->assertTrue($this->functionExists($function), "Missing function {$function}");
        }
        foreach ([
            'checkout_confirmation_consumption_id',
            'checkout_confirmation_fingerprint',
            'checkout_confirmed_at',
            'checkout_confirmation_expires_at',
            'checkout_confirmation_consumed_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('front_desk_checkout_executions', $column), "Missing FD-C1 Package 8 column {$column}");
        }
    }

    private function assertQueryFails(callable $callback, string $needle): void
    {
        try {
            $callback();
            $this->fail("Expected query failure containing {$needle}.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($needle, $exception->getMessage());
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

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
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
