<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\PostgresTestCase;

class OwnerActivationFirstTrustLinkageMigrationTest extends PostgresTestCase
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

    public function test_empty_upgrade_changes_only_the_verified_fk_target_and_down_restores_it(): void
    {
        $migration = $this->migration();

        $this->assertSame('first_trust_runs.id', $this->target());
        $migration->down();
        $this->assertSame('property_bootstrap_provisioning_runs.id', $this->target());
        $migration->up();
        $this->assertSame('first_trust_runs.id', $this->target());
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('owner_activations', 'first_trust_run_id'));
        $this->assertDatabaseCount('owner_activations', 0);
        $this->assertDatabaseCount('property_bootstrap_provisioning_runs', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unexpected_previous_fk_target_fails_closed_without_guessing(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement('ALTER TABLE owner_activations DROP CONSTRAINT owner_activations_first_trust_run_id_foreign');
        DB::statement(<<<'SQL'
            ALTER TABLE owner_activations
            ADD CONSTRAINT owner_activations_first_trust_run_id_foreign
            FOREIGN KEY (first_trust_run_id) REFERENCES companies(id)
            ON UPDATE RESTRICT ON DELETE RESTRICT
        SQL);

        try {
            $migration->up();
            $this->fail('An unexpected FK target must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('B5A2A_FIRST_TRUST_LINKAGE_SCHEMA_MISMATCH', $exception->getMessage());
        }

        $this->assertSame('companies.id', $this->target());
    }

    public function test_existing_owner_activation_refuses_forward_relink_without_adoption(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->seedLegacyActivation();

        try {
            $migration->up();
            $this->fail('Existing activation data must block the forward FK transition.');
        } catch (RuntimeException $exception) {
            $this->assertSame('B5A2A_EXISTING_FIRST_TRUST_LINKAGE_RECONCILIATION_REQUIRED', $exception->getMessage());
        }

        $this->assertSame('property_bootstrap_provisioning_runs.id', $this->target());
        $this->assertDatabaseCount('owner_activations', 1);
        $this->assertDatabaseCount('first_trust_runs', 0);
        $this->assertDatabaseCount('property_bootstrap_provisioning_runs', 1);
    }

    public function test_existing_first_trust_data_refuses_down_without_rewrite_or_b4c_creation(): void
    {
        $this->insertRun();
        $b4cBefore = DB::table('property_bootstrap_provisioning_runs')->count();

        try {
            $this->migration()->down();
            $this->fail('Semantic first-trust data must block defensive rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('B5A2A_EXISTING_FIRST_TRUST_LINKAGE_RECONCILIATION_REQUIRED', $exception->getMessage());
        }

        $this->assertSame('first_trust_runs.id', $this->target());
        $this->assertDatabaseCount('first_trust_runs', 1);
        $this->assertSame($b4cBefore, DB::table('property_bootstrap_provisioning_runs')->count());
        $this->assertDatabaseCount('users', 0);
    }

    private function migration(): object
    {
        return require base_path('Modules/Foundation/Authorization/database/migrations/2026_09_27_000002_relink_owner_activations_to_first_trust_runs.php');
    }

    private function target(): string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT target_table.relname AS target_table, target_attribute.attname AS target_column,
                   constraint_record.conname AS constraint_name
            FROM pg_constraint constraint_record
            JOIN pg_class source_table ON source_table.oid = constraint_record.conrelid
            JOIN LATERAL unnest(constraint_record.conkey) WITH ORDINALITY source_key(attnum, ordinal) ON true
            JOIN pg_attribute source_attribute ON source_attribute.attrelid = source_table.oid AND source_attribute.attnum = source_key.attnum
            JOIN pg_class target_table ON target_table.oid = constraint_record.confrelid
            JOIN LATERAL unnest(constraint_record.confkey) WITH ORDINALITY target_key(attnum, ordinal) ON target_key.ordinal = source_key.ordinal
            JOIN pg_attribute target_attribute ON target_attribute.attrelid = target_table.oid AND target_attribute.attnum = target_key.attnum
            WHERE constraint_record.contype = 'f'
              AND source_table.relname = 'owner_activations'
              AND source_attribute.attname = 'first_trust_run_id'
        SQL);

        $this->assertNotNull($row);
        $this->assertSame('owner_activations_first_trust_run_id_foreign', $row->constraint_name);

        return $row->target_table.'.'.$row->target_column;
    }

    private function insertRun(): string
    {
        $id = (string) Str::ulid();
        $now = now();
        DB::table('first_trust_runs')->insert([
            'id' => $id,
            'environment' => 'operational',
            'installation_id' => 'installation/rollback-proof',
            'status' => 'IN_PROGRESS',
            'execution_id' => 'execution/rollback-proof',
            'authorization_id' => 'authorization/rollback-proof',
            'authority_reference' => 'authority/operational/v1',
            'authority_issuer' => 'https://authority.example.test/operational',
            'request_fingerprint' => str_repeat('1', 64),
            'canonical_sha' => str_repeat('2', 40),
            'verifier_key_id' => 'arn:aws:kms:ap-southeast-1:000000000000:key/00000000-0000-0000-0000-000000000000',
            'verifier_bundle_fingerprint' => str_repeat('3', 64),
            'reservation_reference' => 'reservation/rollback-proof',
            'reservation_fingerprint' => str_repeat('4', 64),
            'reservation_reserved_at' => $now,
            'reservation_commit_deadline' => $now->copy()->addMinutes(15),
            'reservation_recovery_deadline' => $now->copy()->addDay(),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
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
            'slug' => 'legacy-linkage-company',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Legacy Property',
            'slug' => 'legacy-linkage-property',
            'code' => 'LEGACYLINK',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Legacy Owner',
            'email' => 'legacy.linkage.owner@example.test',
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
            'idempotency_key' => 'legacy-linkage-placeholder',
            'request_fingerprint' => str_repeat('1', 64),
            'status' => 'in_progress',
            'canonical_sha' => str_repeat('2', 40),
            'source' => 'legacy-linkage-test',
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
            'installation_id' => 'legacy-linkage-installation',
            'first_trust_run_id' => $bootstrapId,
            'user_id' => $userId,
            'company_id' => $companyId,
            'property_id' => $propertyId,
            'canonical_email' => 'legacy.linkage.owner@example.test',
            'pending_role_name' => 'installation-owner',
            'version' => 1,
            'invited_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
