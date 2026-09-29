<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class FirstTrustRunImmutabilityTest extends PostgresTestCase
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

    public function test_insert_requires_in_progress_without_result_consumption_completion_or_failure_state(): void
    {
        foreach (['COMPLETED', 'FAILED'] as $status) {
            $attributes = $this->runAttributes();
            $attributes['status'] = $status;
            $this->assertRejected(
                fn () => DB::table('first_trust_runs')->insert($attributes),
                'B5A2A_FIRST_TRUST_RUN_INSERT_STATUS_INVALID',
            );
        }

        $attributes = $this->runAttributes();
        $attributes['failed_at'] = now();
        $attributes['failure_code'] = 'DETERMINISTIC_FAILURE';
        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->insert($attributes),
            'B5A2A_FIRST_TRUST_RUN_INSERT_STATE_INVALID',
        );

        $id = $this->insertRun();
        $this->assertDatabaseHas('first_trust_runs', ['id' => $id, 'status' => 'IN_PROGRESS']);
    }

    public function test_delete_and_identity_mutation_are_rejected(): void
    {
        $id = $this->insertRun();

        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->delete(),
            'B5A2A_FIRST_TRUST_RUN_DELETE_REJECTED',
        );
        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update(['execution_id' => 'execution/replaced']),
            'B5A2A_FIRST_TRUST_RUN_IDENTITY_IMMUTABLE',
        );

        $this->assertDatabaseHas('first_trust_runs', ['id' => $id, 'execution_id' => 'execution/test']);
    }

    public function test_result_set_is_atomic_matches_membership_and_binds_only_once(): void
    {
        $id = $this->insertRun();
        $foundation = $this->foundation($id);

        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update(['user_id' => $foundation['user_id']]),
            'chk_first_trust_run_result_set',
        );

        $mismatched = $foundation;
        $mismatched['membership_user_id'] = (string) Str::ulid();
        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update($mismatched),
            'chk_first_trust_run_result_set',
        );

        $this->bindFoundation($id, $foundation);
        $this->assertDatabaseHas('first_trust_runs', [
            'id' => $id,
            'user_id' => $foundation['user_id'],
            'membership_user_id' => $foundation['user_id'],
            'property_id' => $foundation['property_id'],
            'membership_property_id' => $foundation['property_id'],
        ]);

        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update(['membership_fingerprint' => str_repeat('9', 64)]),
            'B5A2A_FIRST_TRUST_RUN_RESULT_IMMUTABLE',
        );
        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update([
                'user_id' => null,
                'company_id' => null,
                'property_id' => null,
                'membership_user_id' => null,
                'membership_property_id' => null,
                'membership_fingerprint' => null,
                'owner_activation_id' => null,
                'foundation_committed_at' => null,
            ]),
            'B5A2A_FIRST_TRUST_RUN_RESULT_IMMUTABLE',
        );
    }

    public function test_consumption_requires_foundation_is_atomic_and_binds_only_once(): void
    {
        $id = $this->insertRun();
        $consumption = $this->consumption();

        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update($consumption),
            'B5A2A_FIRST_TRUST_RUN_CONSUMPTION_BEFORE_FOUNDATION',
        );

        $foundation = $this->foundation($id);
        $this->bindFoundation($id, $foundation);
        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update(['consumption_reference' => 'consumption/test']),
            'chk_first_trust_run_consumption_set',
        );

        DB::table('first_trust_runs')->where('id', $id)->update($consumption);
        $this->assertDatabaseHas('first_trust_runs', [
            'id' => $id,
            'consumption_reference' => 'consumption/test',
            'consumption_fingerprint' => str_repeat('7', 64),
        ]);

        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update(['consumption_reference' => 'consumption/replaced']),
            'B5A2A_FIRST_TRUST_RUN_CONSUMPTION_IMMUTABLE',
        );
    }

    public function test_completed_requires_full_evidence_and_is_terminal(): void
    {
        $id = $this->insertRun();
        $foundation = $this->foundation($id);
        $this->bindFoundation($id, $foundation);

        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update([
                'status' => 'COMPLETED',
                'completion_evidence_fingerprint' => str_repeat('8', 64),
                'completed_at' => now(),
            ]),
            'chk_first_trust_run_lifecycle',
        );

        DB::table('first_trust_runs')->where('id', $id)->update([
            ...$this->consumption(),
            'status' => 'COMPLETED',
            'completion_evidence_fingerprint' => str_repeat('8', 64),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('first_trust_runs', ['id' => $id, 'status' => 'COMPLETED']);

        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update(['updated_at' => now()->addSecond()]),
            'B5A2A_FIRST_TRUST_RUN_TERMINAL_IMMUTABLE',
        );
        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->delete(),
            'B5A2A_FIRST_TRUST_RUN_DELETE_REJECTED',
        );
    }

    public function test_failed_is_allowed_only_without_foundation_or_consumption_and_is_terminal(): void
    {
        $id = $this->insertRun();
        DB::table('first_trust_runs')->where('id', $id)->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_code' => 'DETERMINISTIC_LOCAL_FAILURE',
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('first_trust_runs', ['id' => $id, 'status' => 'FAILED']);
        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $id)->update(['status' => 'IN_PROGRESS']),
            'B5A2A_FIRST_TRUST_RUN_TERMINAL_IMMUTABLE',
        );

        $other = $this->insertRun('other');
        $foundation = $this->foundation($other, 'other');
        $this->bindFoundation($other, $foundation);
        $this->assertRejected(
            fn () => DB::table('first_trust_runs')->where('id', $other)->update([
                'status' => 'FAILED',
                'failed_at' => now(),
                'failure_code' => 'ILLEGAL_AFTER_FOUNDATION',
            ]),
            'chk_first_trust_run_lifecycle',
        );
    }

    private function insertRun(string $suffix = 'test'): string
    {
        $attributes = $this->runAttributes($suffix);
        DB::table('first_trust_runs')->insert($attributes);

        return $attributes['id'];
    }

    /** @return array<string, mixed> */
    private function runAttributes(string $suffix = 'test'): array
    {
        $now = now();

        return [
            'id' => (string) Str::ulid(),
            'environment' => 'operational',
            'installation_id' => "installation/{$suffix}",
            'status' => 'IN_PROGRESS',
            'execution_id' => "execution/{$suffix}",
            'authorization_id' => "authorization/{$suffix}",
            'authority_reference' => 'authority/operational/v1',
            'authority_issuer' => 'https://authority.example.test/operational',
            'request_fingerprint' => str_repeat('1', 64),
            'canonical_sha' => str_repeat('2', 40),
            'verifier_key_id' => 'arn:aws:kms:ap-southeast-1:000000000000:key/00000000-0000-0000-0000-000000000000',
            'verifier_bundle_fingerprint' => str_repeat('3', 64),
            'reservation_reference' => "reservation/{$suffix}",
            'reservation_fingerprint' => str_repeat('4', 64),
            'reservation_reserved_at' => $now,
            'reservation_commit_deadline' => $now->copy()->addMinutes(15),
            'reservation_recovery_deadline' => $now->copy()->addDay(),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<string, mixed> */
    private function foundation(string $runId, string $suffix = 'test'): array
    {
        $now = now();
        $userId = (string) Str::ulid();
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $activationId = (string) Str::ulid();

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => "Company {$suffix}",
            'slug' => "company-{$suffix}",
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => "Property {$suffix}",
            'slug' => "property-{$suffix}",
            'code' => strtoupper(substr($suffix, 0, 12)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('users')->insert([
            'id' => $userId,
            'name' => "Owner {$suffix}",
            'email' => "owner.{$suffix}@example.test",
            'password' => null,
            'is_system_admin' => false,
            'is_active' => false,
            'auth_epoch' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('property_user')->insert([
            'property_id' => $propertyId,
            'user_id' => $userId,
            'is_default' => true,
            'status' => 'active',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('owner_activations')->insert([
            'id' => $activationId,
            'status' => 'INVITED',
            'environment' => 'operational',
            'installation_id' => "installation/{$suffix}",
            'first_trust_run_id' => $runId,
            'user_id' => $userId,
            'company_id' => $companyId,
            'property_id' => $propertyId,
            'canonical_email' => "owner.{$suffix}@example.test",
            'pending_role_name' => 'installation-owner',
            'version' => 1,
            'invited_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'user_id' => $userId,
            'company_id' => $companyId,
            'property_id' => $propertyId,
            'membership_user_id' => $userId,
            'membership_property_id' => $propertyId,
            'membership_fingerprint' => str_repeat('5', 64),
            'owner_activation_id' => $activationId,
            'foundation_committed_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param array<string, mixed> $foundation */
    private function bindFoundation(string $id, array $foundation): void
    {
        DB::table('first_trust_runs')->where('id', $id)->update($foundation);
    }

    /** @return array<string, mixed> */
    private function consumption(): array
    {
        return [
            'consumption_reference' => 'consumption/test',
            'consumption_fingerprint' => str_repeat('7', 64),
            'external_consumed_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function assertRejected(callable $operation, string $expected): void
    {
        try {
            DB::transaction($operation);
            $this->fail("Database mutation should have been rejected with {$expected}.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
        }
    }
}
