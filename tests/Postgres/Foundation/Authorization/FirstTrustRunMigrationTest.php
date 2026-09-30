<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\PostgresTestCase;

class FirstTrustRunMigrationTest extends PostgresTestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_fresh_schema_has_exact_first_trust_ledger_contract(): void
    {
        $this->assertTrue(Schema::hasTable('first_trust_runs'));

        $columns = collect(Schema::getColumns('first_trust_runs'))->keyBy('name');
        $expected = [
            'id', 'environment', 'installation_id', 'status', 'execution_id', 'authorization_id',
            'authority_reference', 'authority_issuer', 'request_fingerprint', 'canonical_sha',
            'verifier_key_id', 'verifier_bundle_fingerprint', 'reservation_reference',
            'reservation_fingerprint', 'reservation_reserved_at', 'reservation_commit_deadline',
            'reservation_recovery_deadline', 'started_at', 'user_id', 'company_id', 'property_id',
            'membership_user_id', 'membership_property_id', 'membership_fingerprint',
            'owner_activation_id', 'foundation_committed_at', 'consumption_reference',
            'consumption_fingerprint', 'external_consumed_at', 'completion_evidence_fingerprint',
            'completed_at', 'failed_at', 'failure_code', 'created_at', 'updated_at',
        ];

        $this->assertSame($expected, $columns->keys()->all());
        $this->assertFalse($columns->has('deleted_at'));

        foreach (['user_id', 'company_id', 'property_id', 'membership_user_id', 'membership_property_id', 'owner_activation_id'] as $nullable) {
            $this->assertTrue($columns[$nullable]['nullable'], "{$nullable} must be nullable until result binding.");
        }

        foreach (['execution_id', 'authorization_id', 'request_fingerprint', 'canonical_sha', 'reservation_reference'] as $required) {
            $this->assertFalse($columns[$required]['nullable'], "{$required} must be required at insert.");
        }

        $types = collect(DB::select(<<<'SQL'
            SELECT column_name, data_type, character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'first_trust_runs'
        SQL))->keyBy('column_name');

        foreach (['id' => 26, 'request_fingerprint' => 64, 'canonical_sha' => 40, 'membership_fingerprint' => 64] as $column => $length) {
            $this->assertSame('character', $types[$column]->data_type);
            $this->assertSame($length, $types[$column]->character_maximum_length);
        }
        foreach (['environment' => 20, 'installation_id' => 100, 'execution_id' => 200, 'authority_issuer' => 300, 'failure_code' => 100] as $column => $length) {
            $this->assertSame('character varying', $types[$column]->data_type);
            $this->assertSame($length, $types[$column]->character_maximum_length);
        }
        foreach (['reservation_reserved_at', 'reservation_commit_deadline', 'reservation_recovery_deadline', 'started_at', 'foundation_committed_at', 'external_consumed_at', 'completed_at', 'failed_at', 'created_at', 'updated_at'] as $column) {
            $this->assertSame('timestamp without time zone', $types[$column]->data_type);
        }
    }

    public function test_unique_check_foreign_key_and_trigger_contracts_exist(): void
    {
        $constraints = collect(DB::select(<<<'SQL'
            SELECT conname, contype, pg_get_constraintdef(oid, true) AS definition
            FROM pg_constraint
            WHERE conrelid = 'first_trust_runs'::regclass
            ORDER BY conname
        SQL))->keyBy('conname');

        foreach ([
            'uq_first_trust_run_installation',
            'uq_first_trust_run_execution',
            'uq_first_trust_run_authorization',
            'uq_first_trust_run_owner_activation',
        ] as $unique) {
            $this->assertSame('u', $constraints[$unique]->contype);
        }
        $this->assertStringContainsString('UNIQUE (environment, installation_id)', $constraints['uq_first_trust_run_installation']->definition);
        $this->assertStringContainsString('UNIQUE (execution_id)', $constraints['uq_first_trust_run_execution']->definition);
        $this->assertStringContainsString('UNIQUE (authorization_id)', $constraints['uq_first_trust_run_authorization']->definition);
        $this->assertStringContainsString('UNIQUE (owner_activation_id)', $constraints['uq_first_trust_run_owner_activation']->definition);

        foreach ([
            'chk_first_trust_run_environment',
            'chk_first_trust_run_status',
            'chk_first_trust_run_nonblank_identity',
            'chk_first_trust_run_hashes',
            'chk_first_trust_run_reservation_times',
            'chk_first_trust_run_result_set',
            'chk_first_trust_run_consumption_set',
            'chk_first_trust_run_lifecycle',
        ] as $check) {
            $this->assertSame('c', $constraints[$check]->contype);
        }

        $foreignKeys = collect(DB::select(<<<'SQL'
            SELECT source_attribute.attname AS source_column,
                   target_table.relname AS target_table,
                   target_attribute.attname AS target_column,
                   constraint_record.confupdtype AS update_action,
                   constraint_record.confdeltype AS delete_action
            FROM pg_constraint constraint_record
            JOIN pg_class source_table ON source_table.oid = constraint_record.conrelid
            JOIN LATERAL unnest(constraint_record.conkey) WITH ORDINALITY source_key(attnum, ordinal) ON true
            JOIN pg_attribute source_attribute ON source_attribute.attrelid = source_table.oid AND source_attribute.attnum = source_key.attnum
            JOIN pg_class target_table ON target_table.oid = constraint_record.confrelid
            JOIN LATERAL unnest(constraint_record.confkey) WITH ORDINALITY target_key(attnum, ordinal) ON target_key.ordinal = source_key.ordinal
            JOIN pg_attribute target_attribute ON target_attribute.attrelid = target_table.oid AND target_attribute.attnum = target_key.attnum
            WHERE constraint_record.contype = 'f' AND source_table.relname = 'first_trust_runs'
        SQL))->keyBy('source_column');

        $expected = [
            'user_id' => 'users',
            'company_id' => 'companies',
            'property_id' => 'properties',
            'membership_user_id' => 'users',
            'membership_property_id' => 'properties',
            'owner_activation_id' => 'owner_activations',
        ];
        foreach ($expected as $column => $table) {
            $this->assertSame($table, $foreignKeys[$column]->target_table);
            $this->assertSame('id', $foreignKeys[$column]->target_column);
            $this->assertSame('r', $foreignKeys[$column]->update_action);
            $this->assertSame('r', $foreignKeys[$column]->delete_action);
        }

        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE tgrelid = 'first_trust_runs'::regclass AND tgname = 'trg_first_trust_runs_guard' AND NOT tgisinternal"));
        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname = 'first_trust_runs_guard'"));
    }

    public function test_existing_owner_activation_blocks_000001_before_table_creation(): void
    {
        $linkageMigration = require base_path('Modules/Foundation/Authorization/database/migrations/2026_09_27_000002_relink_owner_activations_to_first_trust_runs.php');
        $ledgerMigration = require base_path('Modules/Foundation/Authorization/database/migrations/2026_09_27_000001_create_first_trust_runs_table.php');

        $linkageMigration->down();
        $ledgerMigration->down();
        $this->seedLegacyActivation();

        try {
            $ledgerMigration->up();
            $this->fail('Historical OwnerActivation must block ledger creation before any partial schema is applied.');
        } catch (RuntimeException $exception) {
            $this->assertSame('B5A2A_EXISTING_OWNER_ACTIVATION_RECONCILIATION_REQUIRED', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('first_trust_runs'));
        $this->assertDatabaseCount('owner_activations', 1);
    }

    public function test_non_postgres_migration_contract_is_explicitly_fail_closed(): void
    {
        $source = file_get_contents(base_path('Modules/Foundation/Authorization/database/migrations/2026_09_27_000001_create_first_trust_runs_table.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("DB::getDriverName() !== 'pgsql'", $source);
        $this->assertStringContainsString('B5A2A_POSTGRESQL_REQUIRED', $source);
    }

    private function seedLegacyActivation(): void
    {
        $now = now();
        $userId = (string) Str::ulid();
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $bootstrapId = (string) Str::ulid();

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'Legacy Company',
            'slug' => 'legacy-company',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Legacy Property',
            'slug' => 'legacy-property',
            'code' => 'LEGACY',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Legacy Owner',
            'email' => 'legacy.owner@example.test',
            'password' => null,
            'is_system_admin' => false,
            'is_active' => false,
            'auth_epoch' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('property_bootstrap_provisioning_runs')->insert([
            'id' => $bootstrapId,
            'environment' => 'operational',
            'idempotency_key' => 'legacy-first-trust-placeholder',
            'request_fingerprint' => str_repeat('1', 64),
            'status' => 'in_progress',
            'canonical_sha' => str_repeat('2', 40),
            'source' => 'legacy-test',
            'initiated_by' => $userId,
            'started_at' => $now,
            'evidence' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('owner_activations')->insert([
            'id' => (string) Str::ulid(),
            'status' => 'INVITED',
            'environment' => 'operational',
            'installation_id' => 'legacy-installation',
            'first_trust_run_id' => $bootstrapId,
            'user_id' => $userId,
            'company_id' => $companyId,
            'property_id' => $propertyId,
            'canonical_email' => 'legacy.owner@example.test',
            'pending_role_name' => 'installation-owner',
            'version' => 1,
            'invited_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
