<?php

namespace Tests\Postgres\Foundation\Authorization;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\FirstTrustRun;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningEnvironmentEnum;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningStatusEnum;
use Modules\Foundation\Property\Models\PropertyBootstrapProvisioningRun;
use Modules\Foundation\Property\Services\PropertyBootstrapProvenanceService;
use Modules\Foundation\User\Models\User;
use RuntimeException;
use Tests\PostgresTestCase;

final class FirstTrustB4CProvenanceLinkageTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private const REQUEST_FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const EVIDENCE_FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const CANONICAL_SHA = 'f56a1031cd63287c8cff37edf9f88245ad704220';

    private PropertyBootstrapProvenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'first-trust.environment' => null,
            'first-trust.installation_id' => null,
        ]);
        $this->service = app(PropertyBootstrapProvenanceService::class);
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('property_bootstrap_provisioning_runs')) {
            DB::statement('TRUNCATE TABLE property_bootstrap_provisioning_runs, first_trust_runs, owner_activations CASCADE');
        }

        parent::tearDown();
    }

    public function test_schema_has_nullable_restrictive_unique_first_trust_link(): void
    {
        $column = DB::selectOne(<<<'SQL'
            SELECT is_nullable, data_type, character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'property_bootstrap_provisioning_runs'
              AND column_name = 'first_trust_run_id'
        SQL);
        $foreign = DB::selectOne(<<<'SQL'
            SELECT target.relname AS target_table, target_attribute.attname AS target_column,
                   relation.confupdtype AS update_action, relation.confdeltype AS delete_action
            FROM pg_constraint relation
            JOIN pg_class source ON source.oid = relation.conrelid
            JOIN pg_class target ON target.oid = relation.confrelid
            JOIN LATERAL unnest(relation.confkey) AS target_key(attnum) ON true
            JOIN pg_attribute target_attribute
              ON target_attribute.attrelid = target.oid
             AND target_attribute.attnum = target_key.attnum
            WHERE relation.contype = 'f'
              AND source.relname = 'property_bootstrap_provisioning_runs'
              AND relation.conname = 'fk_pbpr_first_trust_run'
        SQL);
        $index = DB::selectOne(<<<'SQL'
            SELECT indexdef
            FROM pg_indexes
            WHERE schemaname = current_schema()
              AND tablename = 'property_bootstrap_provisioning_runs'
              AND indexname = 'uq_pbpr_first_trust_run'
        SQL);

        $this->assertSame('YES', $column->is_nullable);
        $this->assertSame('character', $column->data_type);
        $this->assertSame(26, (int) $column->character_maximum_length);
        $this->assertSame('first_trust_runs', $foreign->target_table);
        $this->assertSame('id', $foreign->target_column);
        $this->assertSame('r', $foreign->update_action);
        $this->assertSame('r', $foreign->delete_action);
        $this->assertStringContainsString('UNIQUE', $index->indexdef);
        $this->assertStringContainsString('first_trust_run_id IS NOT NULL', $index->indexdef);
    }

    public function test_forward_upgrade_preserves_legacy_rows_without_fabricating_a_link(): void
    {
        $migration = $this->migration();
        $migration->down();

        $actor = $this->actor();
        $legacy = $this->rawB4CRun($actor, 'legacy-upgrade');
        unset($legacy['first_trust_run_id']);
        DB::table('property_bootstrap_provisioning_runs')->insert($legacy);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('property_bootstrap_provisioning_runs', 'first_trust_run_id'));
        $this->assertNull(DB::table('property_bootstrap_provisioning_runs')->where('id', $legacy['id'])->value('first_trust_run_id'));
    }

    public function test_rollback_refuses_to_destroy_linked_provenance(): void
    {
        $fixture = $this->completedFirstTrust('rollback');
        $this->actingAs($fixture['actor']);
        $this->startLinked($fixture, 'rollback-linked');

        try {
            $this->migration()->down();
            $this->fail('Rollback must refuse while linked provenance exists.');
        } catch (RuntimeException $exception) {
            $this->assertSame('B5A3_FIRST_TRUST_PROVENANCE_ROLLBACK_RECONCILIATION_REQUIRED', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('property_bootstrap_provisioning_runs', 'first_trust_run_id'));
    }

    public function test_completed_active_first_trust_links_to_the_authenticated_human(): void
    {
        $fixture = $this->completedFirstTrust('happy');
        $this->actingAs($fixture['actor']);

        $run = $this->startLinked($fixture, 'happy-path');

        $this->assertSame($fixture['run']->id, $run->first_trust_run_id);
        $this->assertSame($fixture['run']->id, $run->firstTrustRun->id);
        $this->assertSame($fixture['actor']->id, $run->initiated_by);
    }

    public function test_missing_in_progress_failed_and_inactive_activation_first_trust_fail_closed(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);

        foreach (['IN_PROGRESS', 'FAILED'] as $status) {
            $run = $this->unfinishedFirstTrust(strtolower($status), $status);
            $this->configure($run->environment, $run->installation_id);
            $this->assertRuntimeCode(
                PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
                fn () => $this->service->start(
                    $actor,
                    PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                    'status-'.strtolower($status),
                    self::REQUEST_FINGERPRINT,
                    self::CANONICAL_SHA,
                    'b5a3-test',
                    $run->id,
                ),
            );
        }

        config(['first-trust.environment' => 'OPERATIONAL', 'first-trust.installation_id' => 'missing-installation']);
        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
            fn () => $this->service->start(
                $actor,
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'missing',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-test',
                (string) Str::ulid(),
            ),
        );

        $inactiveActivation = $this->completedFirstTrust('activation', activate: false);
        $this->actingAs($inactiveActivation['actor']);
        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
            fn () => $this->startLinked($inactiveActivation, 'inactive-activation'),
        );
    }

    public function test_linked_start_preserves_authenticated_active_actor_boundary(): void
    {
        $fixture = $this->completedFirstTrust('actor-boundary');

        $this->assertExceptionClass(
            fn () => $this->startLinked($fixture, 'unauthenticated'),
            AuthorizationException::class,
        );

        $differentActor = $this->actor();
        $this->actingAs($differentActor);
        $this->assertExceptionClass(
            fn () => $this->startLinked($fixture, 'different-authenticated'),
            AuthorizationException::class,
        );

        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_BINDING_MISMATCH,
            fn () => $this->service->start(
                $differentActor,
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'deployment-actor-substitution',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'deployment-actor',
                $fixture['run']->id,
            ),
        );

        $this->actingAs($fixture['actor']);
        DB::table('users')->where('id', $fixture['actor']->id)->update(['is_active' => false]);
        $this->assertExceptionClass(
            fn () => $this->startLinked($fixture, 'inactive-authenticated'),
            AuthorizationException::class,
        );
    }

    public function test_environment_installation_and_exact_link_are_required_without_null_bypass(): void
    {
        $fixture = $this->completedFirstTrust('binding');
        $this->actingAs($fixture['actor']);

        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_REQUIRED,
            fn () => $this->service->start(
                $fixture['actor'],
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'null-bypass',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-test',
            ),
        );

        config(['first-trust.installation_id' => 'another-installation']);
        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_BINDING_MISMATCH,
            fn () => $this->service->start(
                $fixture['actor'],
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'installation-mismatch',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-test',
                $fixture['run']->id,
            ),
        );

        config(['first-trust.environment' => 'REHEARSAL', 'first-trust.installation_id' => $fixture['run']->installation_id]);
        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_BINDING_MISMATCH,
            fn () => $this->service->start(
                $fixture['actor'],
                PropertyBootstrapProvisioningEnvironmentEnum::Rehearsal,
                'environment-mismatch',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-test',
                $fixture['run']->id,
            ),
        );

        config(['first-trust.environment' => 'OPERATIONAL', 'first-trust.installation_id' => null]);
        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
            fn () => $this->service->start(
                $fixture['actor'],
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'missing-config',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-test',
                $fixture['run']->id,
            ),
        );
    }

    public function test_legacy_null_link_remains_available_only_without_applicable_first_trust(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);

        $run = $this->service->start(
            $actor,
            PropertyBootstrapProvisioningEnvironmentEnum::Operational,
            'legacy-null',
            self::REQUEST_FINGERPRINT,
            self::CANONICAL_SHA,
            'legacy-b4c',
        );

        $this->assertNull($run->first_trust_run_id);
    }

    public function test_exact_retry_is_mutation_free_and_changed_link_fails_closed(): void
    {
        $fixture = $this->completedFirstTrust('retry');
        $this->actingAs($fixture['actor']);
        $run = $this->startLinked($fixture, 'linked-retry');
        $updatedAt = $run->updated_at;

        $retry = $this->startLinked($fixture, 'linked-retry');
        $this->assertSame($run->id, $retry->id);
        $this->assertTrue($updatedAt->equalTo($retry->updated_at));
        $this->assertSame($fixture['run']->id, $retry->first_trust_run_id);

        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_BINDING_MISMATCH,
            fn () => $this->service->start(
                $fixture['actor'],
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'linked-retry',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-test',
                (string) Str::ulid(),
            ),
        );
    }

    public function test_company_property_completion_and_failed_terminal_state_preserve_exact_link(): void
    {
        $fixture = $this->completedFirstTrust('domain-binding');
        $this->actingAs($fixture['actor']);
        $run = $this->startLinked($fixture, 'domain-binding');
        $otherCompany = CompanyFactory::new()->create();
        $otherProperty = PropertyFactory::new()->create(['company_id' => $otherCompany->id]);

        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_BINDING_MISMATCH,
            fn () => $this->service->bindCompany($run, $fixture['actor'], $otherCompany->id),
        );
        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_BINDING_MISMATCH,
            fn () => $this->service->bindProperty($run, $fixture['actor'], $otherProperty->id),
        );

        $run = $this->service->bindCompany($run, $fixture['actor'], $fixture['company']->id);
        $companyUpdatedAt = $run->updated_at;
        $run = $this->service->bindCompany($run, $fixture['actor'], $fixture['company']->id);
        $this->assertTrue($companyUpdatedAt->equalTo($run->updated_at));
        $run = $this->service->bindProperty($run, $fixture['actor'], $fixture['property']->id);
        $run = $this->service->complete($run, $fixture['actor'], ['phase' => 'complete'], self::EVIDENCE_FINGERPRINT);

        $this->assertSame(PropertyBootstrapProvisioningStatusEnum::Completed, $run->status);
        $this->assertSame($fixture['run']->id, $run->first_trust_run_id);
        $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')->where('id', $run->id)->update([
            'first_trust_run_id' => null,
        ]));

        $failedFixture = $this->completedFirstTrust('failed-b4c');
        $this->actingAs($failedFixture['actor']);
        $failed = $this->startLinked($failedFixture, 'failed-b4c');
        $failed = $this->service->fail($failed, $failedFixture['actor'], 'CONTROLLED_FAILURE');
        $this->assertSame($failedFixture['run']->id, $failed->first_trust_run_id);
        $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')->where('id', $failed->id)->update([
            'first_trust_run_id' => null,
        ]));
    }

    public function test_database_guard_rejects_wrong_actor_environment_company_property_and_noncompleted_link(): void
    {
        $fixture = $this->completedFirstTrust('raw-guard');

        $wrongActor = $this->actor();
        $this->assertRawInsertRejected($this->rawB4CRun($wrongActor, 'wrong-actor', $fixture['run']->id));

        $wrongEnvironment = $this->rawB4CRun($fixture['actor'], 'wrong-environment', $fixture['run']->id);
        $wrongEnvironment['environment'] = 'rehearsal';
        $this->assertRawInsertRejected($wrongEnvironment);

        $wrongCompany = $this->rawB4CRun($fixture['actor'], 'wrong-company', $fixture['run']->id);
        $wrongCompany['company_id'] = CompanyFactory::new()->create()->id;
        $this->assertRawInsertRejected($wrongCompany);

        $wrongProperty = $this->rawB4CRun($fixture['actor'], 'wrong-property', $fixture['run']->id);
        $wrongProperty['property_id'] = PropertyFactory::new()->create(['company_id' => $fixture['company']->id])->id;
        $this->assertRawInsertRejected($wrongProperty);

        $inProgress = $this->unfinishedFirstTrust('raw-in-progress', 'IN_PROGRESS');
        $this->assertRawInsertRejected($this->rawB4CRun($fixture['actor'], 'noncompleted', $inProgress->id));
    }

    public function test_database_prevents_late_link_relink_and_duplicate_first_trust_history(): void
    {
        $fixture = $this->completedFirstTrust('immutable-link');
        $this->actingAs($fixture['actor']);
        $linked = $this->startLinked($fixture, 'linked');

        $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')->where('id', $linked->id)->update([
            'first_trust_run_id' => null,
        ]));

        config(['first-trust.environment' => null, 'first-trust.installation_id' => null]);
        $legacy = $this->service->start(
            $fixture['actor'],
            PropertyBootstrapProvisioningEnvironmentEnum::Operational,
            'legacy-late-link',
            self::REQUEST_FINGERPRINT,
            self::CANONICAL_SHA,
            'legacy-b4c',
        );
        $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')->where('id', $legacy->id)->update([
            'first_trust_run_id' => $fixture['run']->id,
        ]));

        $duplicate = $this->rawB4CRun($fixture['actor'], 'duplicate-link', $fixture['run']->id);
        $this->assertRawInsertRejected($duplicate);
        $this->assertSame(1, PropertyBootstrapProvisioningRun::query()->where('first_trust_run_id', $fixture['run']->id)->count());
    }

    public function test_two_process_exact_linked_starts_converge_to_one_run(): void
    {
        $fixture = $this->completedFirstTrust('concurrency');
        $results = $this->spawnLinkedStartWorkers($fixture, ['same-key', 'same-key']);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(0, $result['_exit_code'], $result['_stderr'] ?? ($result['error'] ?? ''));
            $this->assertSame($fixture['run']->id, $result['first_trust_run_id']);
            $this->assertSame(1, $result['row_count']);
        }
        $this->assertSame($results[0]['run_id'], $results[1]['run_id']);
        $this->assertNotSame($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotSame($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);
    }

    private function completedFirstTrust(string $suffix, bool $activate = true): array
    {
        $environment = 'operational';
        $installation = "installation-{$suffix}";
        $now = now();
        $actor = $this->actor();
        $company = CompanyFactory::new()->create();
        $property = PropertyFactory::new()->create(['company_id' => $company->id]);
        $run = $this->unfinishedFirstTrust($suffix, 'IN_PROGRESS', $environment, $installation);
        $activationId = (string) Str::ulid();

        DB::table('property_user')->insert([
            'property_id' => $property->id,
            'user_id' => $actor->id,
            'is_default' => true,
            'status' => 'active',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('owner_activations')->insert([
            'id' => $activationId,
            'status' => 'INVITED',
            'environment' => $environment,
            'installation_id' => $installation,
            'first_trust_run_id' => $run->id,
            'user_id' => $actor->id,
            'company_id' => $company->id,
            'property_id' => $property->id,
            'canonical_email' => $actor->email,
            'pending_role_name' => 'installation-owner',
            'version' => 1,
            'invited_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('first_trust_runs')->where('id', $run->id)->update([
            'user_id' => $actor->id,
            'company_id' => $company->id,
            'property_id' => $property->id,
            'membership_user_id' => $actor->id,
            'membership_property_id' => $property->id,
            'membership_fingerprint' => str_repeat('5', 64),
            'owner_activation_id' => $activationId,
            'foundation_committed_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('first_trust_runs')->where('id', $run->id)->update([
            'consumption_reference' => "consumption-{$suffix}",
            'consumption_fingerprint' => str_repeat('6', 64),
            'external_consumed_at' => $now,
            'status' => 'COMPLETED',
            'completion_evidence_fingerprint' => str_repeat('7', 64),
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        if ($activate) {
            $this->activate($activationId, $now);
        }

        $this->configure($environment, $installation);

        return [
            'run' => FirstTrustRun::query()->findOrFail($run->id),
            'actor' => $actor->fresh(),
            'company' => $company,
            'property' => $property,
        ];
    }

    private function unfinishedFirstTrust(
        string $suffix,
        string $status,
        string $environment = 'operational',
        ?string $installation = null,
    ): FirstTrustRun {
        $now = now();
        $id = (string) Str::ulid();
        $installation ??= "installation-{$suffix}";
        DB::table('first_trust_runs')->insert([
            'id' => $id,
            'environment' => $environment,
            'installation_id' => $installation,
            'status' => 'IN_PROGRESS',
            'execution_id' => "execution-{$suffix}",
            'authorization_id' => "authorization-{$suffix}",
            'authority_reference' => 'authority/test/v1',
            'authority_issuer' => 'https://authority.example.test',
            'request_fingerprint' => str_repeat('1', 64),
            'canonical_sha' => self::CANONICAL_SHA,
            'verifier_key_id' => "key-{$suffix}",
            'verifier_bundle_fingerprint' => str_repeat('2', 64),
            'reservation_reference' => "reservation-{$suffix}",
            'reservation_fingerprint' => str_repeat('3', 64),
            'reservation_reserved_at' => $now,
            'reservation_commit_deadline' => $now->copy()->addMinutes(15),
            'reservation_recovery_deadline' => $now->copy()->addDay(),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($status === 'FAILED') {
            DB::table('first_trust_runs')->where('id', $id)->update([
                'status' => 'FAILED',
                'failed_at' => $now,
                'failure_code' => 'CONTROLLED_FAILURE',
                'updated_at' => $now,
            ]);
        }

        return FirstTrustRun::query()->findOrFail($id);
    }

    private function activate(string $activationId, mixed $now): void
    {
        $steps = [
            ['EMAIL_VERIFIED', 'email_verified_at'],
            ['PASSWORD_ESTABLISHED', 'password_established_at'],
            ['MFA_ENROLLING', 'mfa_enrollment_started_at'],
            ['MFA_ENROLLED', 'mfa_enrolled_at'],
            ['ACTIVE', 'activated_at'],
        ];
        foreach ($steps as $index => [$status, $timestamp]) {
            DB::table('owner_activations')->where('id', $activationId)->update([
                'status' => $status,
                $timestamp => $now,
                'version' => $index + 2,
                'updated_at' => $now,
            ]);
        }
    }

    private function actor(array $attributes = []): User
    {
        return UserFactory::new()->create(array_merge(['is_active' => true], $attributes));
    }

    private function configure(string $environment, string $installation): void
    {
        config([
            'first-trust.environment' => strtoupper($environment),
            'first-trust.installation_id' => $installation,
        ]);
    }

    private function startLinked(array $fixture, string $key): PropertyBootstrapProvisioningRun
    {
        return $this->service->start(
            $fixture['actor'],
            PropertyBootstrapProvisioningEnvironmentEnum::Operational,
            $key,
            self::REQUEST_FINGERPRINT,
            self::CANONICAL_SHA,
            'b5a3-test',
            $fixture['run']->id,
        );
    }

    private function rawB4CRun(User $actor, string $key, ?string $firstTrustRunId = null): array
    {
        $now = now();

        return [
            'id' => (string) Str::ulid(),
            'environment' => 'operational',
            'idempotency_key' => $key,
            'request_fingerprint' => self::REQUEST_FINGERPRINT,
            'status' => 'in_progress',
            'canonical_sha' => self::CANONICAL_SHA,
            'source' => 'b5a3-raw-test',
            'initiated_by' => $actor->id,
            'first_trust_run_id' => $firstTrustRunId,
            'company_id' => null,
            'property_id' => null,
            'started_at' => $now,
            'completed_at' => null,
            'failed_at' => null,
            'failure_code' => null,
            'evidence' => json_encode([], JSON_THROW_ON_ERROR),
            'evidence_fingerprint' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function assertRawInsertRejected(array $row): void
    {
        $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')->insert($row));
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The raw database mutation must be rejected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function assertRuntimeCode(string $expected, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected runtime code {$expected}.");
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception->getMessage());
        }
    }

    private function assertExceptionClass(callable $operation, string $expectedClass): void
    {
        try {
            $operation();
            $this->fail("Expected exception {$expectedClass}.");
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expectedClass, $exception);
        }
    }

    private function migration(): object
    {
        return require base_path('Modules/Foundation/Property/database/migrations/2026_10_23_000001_add_first_trust_provenance_to_property_bootstrap_runs.php');
    }

    private function spawnLinkedStartWorkers(array $fixture, array $keys): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'b5a3-concurrency-'.Str::lower(Str::random(8));
        mkdir($directory, 0700, true);
        $workerFile = $directory.DIRECTORY_SEPARATOR.'worker.php';
        $barrier = $directory.DIRECTORY_SEPARATOR.'start';
        file_put_contents($workerFile, $this->workerSource());
        $processes = [];
        $created = [$workerFile];

        try {
            foreach (['a', 'b'] as $index => $workerId) {
                $argumentsFile = $directory.DIRECTORY_SEPARATOR."arguments-{$workerId}.json";
                $resultFile = $directory.DIRECTORY_SEPARATOR."result-{$workerId}.json";
                $stderrFile = $directory.DIRECTORY_SEPARATOR."stderr-{$workerId}.txt";
                array_push($created, $argumentsFile, $resultFile, $stderrFile, "{$barrier}-ready-{$workerId}");
                file_put_contents($argumentsFile, json_encode([
                    'worker_id' => $workerId,
                    'actor_id' => $fixture['actor']->id,
                    'first_trust_run_id' => $fixture['run']->id,
                    'installation_id' => $fixture['run']->installation_id,
                    'idempotency_key' => $keys[$index],
                    'barrier' => $barrier,
                    'result_file' => $resultFile,
                ], JSON_THROW_ON_ERROR));
                $specification = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
                $process = proc_open(
                    [PHP_BINARY, $workerFile, base_path(), $argumentsFile],
                    $specification,
                    $pipes,
                    base_path(),
                    array_merge(getenv(), [
                        'APP_ENV' => 'testing',
                        'DB_CONNECTION' => 'pgsql',
                        'DB_DATABASE' => 'ivorq_testing',
                        'FIRST_TRUST_ENVIRONMENT' => 'OPERATIONAL',
                        'FIRST_TRUST_INSTALLATION_ID' => $fixture['run']->installation_id,
                    ]),
                );
                if (! is_resource($process)) {
                    $this->fail('Unable to spawn the B5A3 concurrency worker.');
                }
                fclose($pipes[0]);
                $processes[] = ['process' => $process, 'result' => $resultFile, 'stderr' => $stderrFile];
            }

            $results = [];
            foreach ($processes as $process) {
                $exitCode = $this->waitForProcess($process['process'], 20);
                $decoded = is_file($process['result'])
                    ? json_decode((string) file_get_contents($process['result']), true)
                    : ['error' => 'missing result'];
                $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result'];
                $decoded['_exit_code'] = $exitCode;
                $decoded['_stderr'] = is_file($process['stderr']) ? trim((string) file_get_contents($process['stderr'])) : '';
                $results[] = $decoded;
            }

            return $results;
        } finally {
            foreach ($created as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
    }

    private function waitForProcess($process, int $seconds): int
    {
        $deadline = microtime(true) + $seconds;
        do {
            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                proc_close($process);

                return $exitCode;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        proc_close($process);

        return 124;
    }

    private function workerSource(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningEnvironmentEnum;
use Modules\Foundation\Property\Models\PropertyBootstrapProvisioningRun;
use Modules\Foundation\Property\Services\PropertyBootstrapProvenanceService;
use Modules\Foundation\User\Models\User;

$root = $argv[1];
$arguments = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $actor = User::query()->findOrFail($arguments['actor_id']);
    Auth::login($actor);
    file_put_contents($arguments['barrier'].'-ready-'.$arguments['worker_id'], 'ready');
    $deadline = microtime(true) + 10;
    while (count(glob($arguments['barrier'].'-ready-*') ?: []) < 2 && microtime(true) < $deadline) {
        usleep(25000);
    }
    $run = app(PropertyBootstrapProvenanceService::class)->start(
        $actor,
        PropertyBootstrapProvisioningEnvironmentEnum::Operational,
        $arguments['idempotency_key'],
        str_repeat('a', 64),
        'f56a1031cd63287c8cff37edf9f88245ad704220',
        'b5a3-test',
        $arguments['first_trust_run_id'],
    );
    file_put_contents($arguments['result_file'], json_encode([
        'run_id' => $run->id,
        'first_trust_run_id' => $run->first_trust_run_id,
        'row_count' => PropertyBootstrapProvisioningRun::query()->count(),
        'php_pid' => getmypid(),
        'pg_backend_pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $throwable) {
    file_put_contents($arguments['result_file'], json_encode(['error' => $throwable::class.': '.$throwable->getMessage()], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP;
    }
}
