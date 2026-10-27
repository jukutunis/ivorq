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
use Modules\Foundation\Authorization\Services\FirstTrustOrchestrator;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningEnvironmentEnum;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningStatusEnum;
use Modules\Foundation\Property\Models\PropertyBootstrapProvisioningRun;
use Modules\Foundation\Property\Services\PropertyBootstrapProvenanceService;
use Modules\Foundation\User\Models\User;
use RuntimeException;
use Symfony\Component\Process\Process;
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

    public function test_config_removal_cannot_restore_legacy_null_link_after_completed_first_trust(): void
    {
        $fixture = $this->completedFirstTrust('config-removal');
        $this->actingAs($fixture['actor']);
        config([
            'first-trust.environment' => null,
            'first-trust.installation_id' => null,
        ]);

        foreach ([null, $fixture['run']->id] as $index => $firstTrustRunId) {
            $this->assertRuntimeCode(
                PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
                fn () => $this->service->start(
                    $fixture['actor'],
                    PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                    "config-removal-{$index}",
                    self::REQUEST_FINGERPRINT,
                    self::CANONICAL_SHA,
                    'b5a3-r1-test',
                    $firstTrustRunId,
                ),
            );
        }

        $this->assertSame(0, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_config_removal_fails_closed_when_in_progress_history_exists(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $this->unfinishedFirstTrust('removed-config-in-progress', 'IN_PROGRESS');

        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
            fn () => $this->service->start(
                $actor,
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'removed-config-in-progress',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-r1-test',
            ),
        );

        $this->assertSame(0, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_config_removal_fails_closed_when_failed_history_exists(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $this->unfinishedFirstTrust('removed-config-failed', 'FAILED');

        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
            fn () => $this->service->start(
                $actor,
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'removed-config-failed',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-r1-test',
            ),
        );

        $this->assertSame(0, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_configured_environment_mismatch_rejects_null_link_in_both_directions(): void
    {
        $operational = $this->completedFirstTrust('environment-operational');
        $this->actingAs($operational['actor']);

        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_BINDING_MISMATCH,
            fn () => $this->service->start(
                $operational['actor'],
                PropertyBootstrapProvisioningEnvironmentEnum::Rehearsal,
                'operational-to-rehearsal',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-r1-test',
            ),
        );

        $rehearsal = $this->completedFirstTrust('environment-rehearsal', environment: 'rehearsal');
        $this->actingAs($rehearsal['actor']);

        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_BINDING_MISMATCH,
            fn () => $this->service->start(
                $rehearsal['actor'],
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'rehearsal-to-operational',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-r1-test',
            ),
        );

        $this->assertSame(0, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_complete_config_with_nonmatching_installation_and_existing_history_fails_closed(): void
    {
        $fixture = $this->completedFirstTrust('nonmatching-history');
        $this->actingAs($fixture['actor']);
        $this->configure('operational', 'different-installation');

        $this->assertRuntimeCode(
            PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
            fn () => $this->service->start(
                $fixture['actor'],
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'nonmatching-history',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'b5a3-r1-test',
            ),
        );

        $this->assertSame(0, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_complete_config_without_first_trust_history_preserves_legacy_null_link(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $this->configure('operational', 'not-yet-established');

        DB::beginTransaction();
        try {
            $run = $this->service->start(
                $actor,
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'configured-empty-ledger',
                self::REQUEST_FINGERPRINT,
                self::CANONICAL_SHA,
                'legacy-b4c',
            );
            $lock = DB::selectOne(<<<'SQL'
                SELECT lock.mode, lock.granted, relation.relname AS relation_name
                FROM pg_locks lock
                JOIN pg_class relation ON relation.oid = lock.relation
                WHERE lock.pid = pg_backend_pid()
                  AND relation.relname = 'first_trust_runs'
                  AND lock.mode = 'ShareLock'
            SQL);
            DB::commit();
        } catch (\Throwable $throwable) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $throwable;
        }

        $this->assertSame(0, FirstTrustRun::query()->count());
        $this->assertNull($run->first_trust_run_id);
        $this->assertSame('first_trust_runs', $lock?->relation_name);
        $this->assertSame('ShareLock', $lock?->mode);
        $this->assertTrue((bool) $lock?->granted);
    }

    public function test_partial_first_trust_configuration_remains_invalid_regardless_of_history(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        $configurations = [
            ['first-trust.environment' => 'OPERATIONAL', 'first-trust.installation_id' => null],
            ['first-trust.environment' => null, 'first-trust.installation_id' => 'partial-installation'],
        ];

        foreach ($configurations as $index => $configuration) {
            config($configuration);
            $this->assertRuntimeCode(
                PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
                fn () => $this->service->start(
                    $actor,
                    PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                    "partial-empty-{$index}",
                    self::REQUEST_FINGERPRINT,
                    self::CANONICAL_SHA,
                    'b5a3-r1-test',
                ),
            );
        }

        $this->unfinishedFirstTrust('partial-history', 'IN_PROGRESS');

        foreach ($configurations as $index => $configuration) {
            config($configuration);
            $this->assertRuntimeCode(
                PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID,
                fn () => $this->service->start(
                    $actor,
                    PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                    "partial-history-{$index}",
                    self::REQUEST_FINGERPRINT,
                    self::CANONICAL_SHA,
                    'b5a3-r1-test',
                ),
            );
        }

        $this->assertSame(0, PropertyBootstrapProvisioningRun::query()->count());
    }

    public function test_legacy_null_link_remains_available_only_without_applicable_first_trust(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);

        $this->assertSame(0, FirstTrustRun::query()->count());

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

        $legacy = $this->rawB4CRun($fixture['actor'], 'legacy-late-link');
        DB::table('property_bootstrap_provisioning_runs')->insert($legacy);
        $this->assertQueryRejected(fn () => DB::table('property_bootstrap_provisioning_runs')->where('id', $legacy['id'])->update([
            'first_trust_run_id' => $fixture['run']->id,
        ]));

        $duplicate = $this->rawB4CRun($fixture['actor'], 'duplicate-link', $fixture['run']->id);
        $this->assertRawInsertRejected($duplicate);
        $this->assertSame(1, PropertyBootstrapProvisioningRun::query()->where('first_trust_run_id', $fixture['run']->id)->count());
    }

    public function test_first_trust_lock_wins_and_serializes_legacy_decision_until_history_is_visible(): void
    {
        $results = $this->spawnLegacyFirstTrustRace('first_trust_wins', $this->actor());
        $firstTrust = $results['first_trust'];
        $b4c = $results['b4c'];

        $this->assertSame(0, $firstTrust['_exit_code'], $firstTrust['_stderr'] ?? ($firstTrust['error'] ?? ''));
        $this->assertSame(0, $b4c['_exit_code'], $b4c['_stderr'] ?? ($b4c['error'] ?? ''));
        $this->assertSame('committed', $firstTrust['outcome']);
        $this->assertContains('ShareRowExclusiveLock', $firstTrust['lock_modes']);
        $this->assertSame('rejected', $b4c['outcome']);
        $this->assertSame(PropertyBootstrapProvenanceService::ERROR_FIRST_TRUST_INVALID, $b4c['error_code']);
        $this->assertSame('Lock', $results['blocked']['wait_event_type']);
        $this->assertSame('relation', $results['blocked']['wait_event']);
        $this->assertSame('first_trust_runs', $results['blocked']['relation_name']);
        $this->assertSame('ShareLock', $results['blocked']['requested_lock_mode']);
        $this->assertFalse($results['blocked']['granted']);
        $this->assertNotSame($firstTrust['php_pid'], $b4c['php_pid']);
        $this->assertNotSame($firstTrust['pg_backend_pid'], $b4c['pg_backend_pid']);
        $this->assertSame(1, FirstTrustRun::query()->count());
        $this->assertSame(0, PropertyBootstrapProvisioningRun::query()->whereNull('first_trust_run_id')->count());
        $this->assertFalse(
            FirstTrustRun::query()->exists()
            && PropertyBootstrapProvisioningRun::query()->whereNull('first_trust_run_id')->exists(),
        );
    }

    public function test_legacy_lock_wins_and_canonical_first_trust_guard_rejects_b4c_history(): void
    {
        $results = $this->spawnLegacyFirstTrustRace('legacy_wins', $this->actor());
        $firstTrust = $results['first_trust'];
        $b4c = $results['b4c'];

        $this->assertSame(0, $firstTrust['_exit_code'], $firstTrust['_stderr'] ?? ($firstTrust['error'] ?? ''));
        $this->assertSame(0, $b4c['_exit_code'], $b4c['_stderr'] ?? ($b4c['error'] ?? ''));
        $this->assertSame('committed', $b4c['outcome']);
        $this->assertContains('ShareLock', $b4c['lock_modes']);
        $this->assertSame('rejected', $firstTrust['outcome']);
        $this->assertSame(FirstTrustOrchestrator::class.'::establish', $firstTrust['entry_point']);
        $this->assertSame(RuntimeException::class, $firstTrust['exception_class']);
        $this->assertSame('FIRST_TRUST_PROTECTED_STATE_NOT_EMPTY', $firstTrust['error_code']);
        $this->assertSame(0, $firstTrust['transaction_level']);
        $this->assertTrue($firstTrust['transaction_id_unassigned']);
        $this->assertSame('Lock', $results['blocked']['wait_event_type']);
        $this->assertSame('relation', $results['blocked']['wait_event']);
        $this->assertSame('first_trust_runs', $results['blocked']['relation_name']);
        $this->assertSame('ShareRowExclusiveLock', $results['blocked']['requested_lock_mode']);
        $this->assertFalse($results['blocked']['granted']);
        $this->assertNotSame($firstTrust['php_pid'], $b4c['php_pid']);
        $this->assertNotSame($firstTrust['pg_backend_pid'], $b4c['pg_backend_pid']);
        $this->assertSame(0, FirstTrustRun::query()->count());
        $this->assertSame(1, PropertyBootstrapProvisioningRun::query()->whereNull('first_trust_run_id')->count());
        $this->assertFalse(
            FirstTrustRun::query()->exists()
            && PropertyBootstrapProvisioningRun::query()->whereNull('first_trust_run_id')->exists(),
        );
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

    private function completedFirstTrust(
        string $suffix,
        bool $activate = true,
        string $environment = 'operational',
    ): array {
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

    private function spawnLegacyFirstTrustRace(string $scenario, User $actor): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'b5a3-r2-race-'.Str::lower(Str::random(8));
        mkdir($directory, 0700, true);
        $workerFile = $directory.DIRECTORY_SEPARATOR.'worker.php';
        $bundleFile = $directory.DIRECTORY_SEPARATOR.'verification-bundle.txt';
        $authorityStateFile = $directory.DIRECTORY_SEPARATOR.'authority-state.json';
        file_put_contents($workerFile, $this->legacyFirstTrustRaceWorkerSource());
        file_put_contents($bundleFile, "B5A3 R3 canonical runtime race bundle\n");
        $release = new Process(['git', '-C', base_path(), 'rev-parse', 'HEAD']);
        $release->mustRun();
        $releaseSha = trim($release->getOutput());
        $processes = [];

        $start = function (string $role) use (
            $scenario,
            $actor,
            $directory,
            $workerFile,
            $bundleFile,
            $authorityStateFile,
            $releaseSha,
            &$processes,
        ): void {
            $argumentsFile = $directory.DIRECTORY_SEPARATOR."arguments-{$role}.json";
            $resultFile = $directory.DIRECTORY_SEPARATOR."result-{$role}.json";
            $stderrFile = $directory.DIRECTORY_SEPARATOR."stderr-{$role}.txt";
            file_put_contents($argumentsFile, json_encode([
                'role' => $role,
                'scenario' => $scenario,
                'actor_id' => $actor->id,
                'barrier_directory' => $directory,
                'bundle_file' => $bundleFile,
                'authority_state_file' => $authorityStateFile,
                'release_sha' => $releaseSha,
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
                    'FIRST_TRUST_ENVIRONMENT' => '',
                    'FIRST_TRUST_INSTALLATION_ID' => '',
                ]),
            );
            if (! is_resource($process)) {
                $this->fail("Unable to spawn the B5A3 R2 {$role} worker.");
            }
            fclose($pipes[0]);
            $processes[$role] = [
                'process' => $process,
                'result' => $resultFile,
                'stderr' => $stderrFile,
            ];
        };

        try {
            if ($scenario === 'first_trust_wins') {
                $start('first_trust');
                $this->waitForRaceSignal($directory.DIRECTORY_SEPARATOR.'first_trust-ready.json');
                $start('b4c');
                $attempted = $this->waitForRaceSignal($directory.DIRECTORY_SEPARATOR.'b4c-attempted.json');
                $blocked = $this->waitForBackendRelationLock((int) $attempted['pg_backend_pid']);
                file_put_contents($directory.DIRECTORY_SEPARATOR.'release-first_trust.signal', 'release');
            } elseif ($scenario === 'legacy_wins') {
                $start('b4c');
                $this->waitForRaceSignal($directory.DIRECTORY_SEPARATOR.'b4c-ready.json');
                $start('first_trust');
                $attempted = $this->waitForRaceSignal($directory.DIRECTORY_SEPARATOR.'first_trust-attempted.json');
                $blocked = $this->waitForBackendRelationLock((int) $attempted['pg_backend_pid']);
                file_put_contents($directory.DIRECTORY_SEPARATOR.'release-b4c.signal', 'release');
            } else {
                $this->fail("Unknown B5A3 R2 race scenario {$scenario}.");
            }

            $results = ['blocked' => $blocked];
            foreach ($processes as $role => $process) {
                $exitCode = $this->waitForProcess($process['process'], 20);
                $decoded = is_file($process['result'])
                    ? json_decode((string) file_get_contents($process['result']), true)
                    : ['error' => 'missing result'];
                $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result'];
                $decoded['_exit_code'] = $exitCode;
                $decoded['_stderr'] = is_file($process['stderr']) ? trim((string) file_get_contents($process['stderr'])) : '';
                $results[$role] = $decoded;
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process['process'])) {
                    $status = proc_get_status($process['process']);
                    if ($status['running'] ?? false) {
                        proc_terminate($process['process']);
                    }
                    proc_close($process['process']);
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
    }

    private function waitForRaceSignal(string $path): array
    {
        $deadline = microtime(true) + 10;
        do {
            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);

                return is_array($decoded) ? $decoded : [];
            }
            usleep(25000);
        } while (microtime(true) < $deadline);

        $this->fail("Timed out waiting for race signal {$path}.");
    }

    private function waitForBackendRelationLock(int $backendPid): array
    {
        $deadline = microtime(true) + 10;
        do {
            $pending = DB::selectOne(
                <<<'SQL'
                    SELECT activity.wait_event_type,
                           activity.wait_event,
                           relation.relname AS relation_name,
                           lock.mode AS requested_lock_mode,
                           lock.granted
                    FROM pg_stat_activity activity
                    JOIN pg_locks lock
                      ON lock.pid = activity.pid
                     AND lock.locktype = 'relation'
                     AND NOT lock.granted
                    JOIN pg_class relation ON relation.oid = lock.relation
                    WHERE activity.pid = ?
                      AND relation.relname = 'first_trust_runs'
                SQL,
                [$backendPid],
            );
            if (($pending?->wait_event_type ?? null) === 'Lock') {
                return [
                    'wait_event_type' => $pending->wait_event_type,
                    'wait_event' => $pending->wait_event,
                    'relation_name' => $pending->relation_name,
                    'requested_lock_mode' => $pending->requested_lock_mode,
                    'granted' => (bool) $pending->granted,
                ];
            }
            usleep(25000);
        } while (microtime(true) < $deadline);

        $this->fail("PostgreSQL backend {$backendPid} did not block on the expected relation lock.");
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

    private function legacyFirstTrustRaceWorkerSource(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustRequest;
use Modules\Foundation\Authorization\Services\FirstTrustEmptyStateGuard;
use Modules\Foundation\Authorization\Services\FirstTrustOrchestrator;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningEnvironmentEnum;
use Modules\Foundation\Property\Models\PropertyBootstrapProvisioningRun;
use Modules\Foundation\Property\Services\PropertyBootstrapProvenanceService;
use Modules\Foundation\User\Models\User;
use Tests\Fakes\FakeFirstTrustAuthority;

$root = $argv[1];
$arguments = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($arguments['role'] === 'first_trust' && $arguments['scenario'] === 'legacy_wins') {
    config([
        'first-trust.environment' => 'REHEARSAL',
        'first-trust.installation_id' => 'b5a3-r3-canonical-race',
        'first-trust.release_sha' => $arguments['release_sha'],
        'first-trust.authority.reference' => 'authority/rehearsal/v1',
        'first-trust.authority.issuer' => 'https://authority.example.test/rehearsal',
        'first-trust.authority.audience' => 'ivorq-installation',
        'first-trust.authority.verification_bundle_path' => $arguments['bundle_file'],
        'mail.default' => 'array',
    ]);
    $app->instance(FirstTrustAuthority::class, new FakeFirstTrustAuthority($arguments['authority_state_file']));
    Notification::fake();
} else {
    config([
        'first-trust.environment' => null,
        'first-trust.installation_id' => null,
    ]);
}

$backendPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
$signal = static function (string $name, array $payload) use ($arguments): void {
    $path = $arguments['barrier_directory'].'/'.$name.'.json';
    $temporary = $path.'.tmp';
    file_put_contents($temporary, json_encode($payload, JSON_THROW_ON_ERROR));
    rename($temporary, $path);
};
$waitForRelease = static function (string $role) use ($arguments): void {
    $path = $arguments['barrier_directory'].'/release-'.$role.'.signal';
    $deadline = microtime(true) + 10;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(25000);
    }
    if (! is_file($path)) {
        throw new RuntimeException("Timed out waiting to release {$role}.");
    }
};
$lockModes = static fn (): array => array_values(array_map(
    static fn (object $lock): string => (string) $lock->mode,
    DB::select(<<<'SQL'
        SELECT lock.mode
        FROM pg_locks lock
        JOIN pg_class relation ON relation.oid = lock.relation
        WHERE lock.pid = pg_backend_pid()
          AND relation.relname = 'first_trust_runs'
          AND lock.granted
        ORDER BY lock.mode
    SQL),
));
$insertFirstTrust = static function (string $suffix): string {
    $id = (string) Str::ulid();
    $now = now();
    DB::table('first_trust_runs')->insert([
        'id' => $id,
        'environment' => 'operational',
        'installation_id' => "race-{$suffix}",
        'status' => 'IN_PROGRESS',
        'execution_id' => "execution-{$suffix}",
        'authorization_id' => "authorization-{$suffix}",
        'authority_reference' => 'authority/test/v1',
        'authority_issuer' => 'https://authority.example.test',
        'request_fingerprint' => str_repeat('1', 64),
        'canonical_sha' => 'f56a1031cd63287c8cff37edf9f88245ad704220',
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

    return $id;
};

try {
    if ($arguments['role'] === 'first_trust') {
        $signal('first_trust-attempted', [
            'php_pid' => getmypid(),
            'pg_backend_pid' => $backendPid,
            'sequence' => 'first_trust_lock_attempted',
        ]);

        if ($arguments['scenario'] === 'first_trust_wins') {
            DB::beginTransaction();
            app(FirstTrustEmptyStateGuard::class)->lockProtectedTables();
            $runId = $insertFirstTrust('first-wins');
            $modes = $lockModes();
            $signal('first_trust-ready', [
                'php_pid' => getmypid(),
                'pg_backend_pid' => $backendPid,
                'sequence' => 'first_trust_locked_and_inserted',
                'lock_modes' => $modes,
            ]);
            $waitForRelease('first_trust');
            DB::commit();
            file_put_contents($arguments['result_file'], json_encode([
                'outcome' => 'committed',
                'run_id' => $runId,
                'lock_modes' => $modes,
                'php_pid' => getmypid(),
                'pg_backend_pid' => $backendPid,
                'barrier_order' => ['first_trust_locked_and_inserted', 'b4c_blocked', 'first_trust_committed'],
            ], JSON_THROW_ON_ERROR));
        } else {
            try {
                $result = app(FirstTrustOrchestrator::class)->establish(
                    new FirstTrustRequest(
                        'Canonical Race Owner',
                        'canonical.race.owner@example.test',
                        'Canonical Race Company',
                        'canonical-race-company',
                        'Canonical Race Property',
                        'canonical-race-property',
                        'R3_RACE',
                        'Asia/Makassar',
                        'IDR',
                    ),
                    'B5A3-R3-CANONICAL-RACE-AUTHORIZATION',
                );
                $payload = [
                    'outcome' => 'completed',
                    'runtime_state' => $result->state,
                ];
            } catch (Throwable $throwable) {
                $payload = [
                    'outcome' => 'rejected',
                    'exception_class' => $throwable::class,
                    'error_code' => $throwable->getMessage(),
                ];
            }

            $transactionLevel = DB::transactionLevel();
            $transactionState = DB::selectOne(
                'SELECT txid_current_if_assigned() IS NULL AS transaction_id_unassigned',
            );
            file_put_contents($arguments['result_file'], json_encode(array_merge($payload, [
                'entry_point' => FirstTrustOrchestrator::class.'::establish',
                'transaction_level' => $transactionLevel,
                'transaction_id_unassigned' => (bool) $transactionState->transaction_id_unassigned,
                'php_pid' => getmypid(),
                'pg_backend_pid' => $backendPid,
                'barrier_order' => ['legacy_locked_and_inserted', 'first_trust_blocked', 'legacy_committed', 'first_trust_rejected'],
            ]), JSON_THROW_ON_ERROR));
        }
    } else {
        $actor = User::query()->findOrFail($arguments['actor_id']);
        Auth::login($actor);
        DB::beginTransaction();
        $signal('b4c-attempted', [
            'php_pid' => getmypid(),
            'pg_backend_pid' => $backendPid,
            'sequence' => 'b4c_legacy_decision_attempted',
        ]);
        try {
            $run = app(PropertyBootstrapProvenanceService::class)->start(
                $actor,
                PropertyBootstrapProvisioningEnvironmentEnum::Operational,
                'b5a3-r2-'.$arguments['scenario'],
                str_repeat('a', 64),
                'f56a1031cd63287c8cff37edf9f88245ad704220',
                'b5a3-r2-race-test',
            );
            $modes = $lockModes();
            $signal('b4c-ready', [
                'php_pid' => getmypid(),
                'pg_backend_pid' => $backendPid,
                'sequence' => 'legacy_locked_and_inserted',
                'lock_modes' => $modes,
            ]);
            $waitForRelease('b4c');
            DB::commit();
            file_put_contents($arguments['result_file'], json_encode([
                'outcome' => 'committed',
                'run_id' => $run->id,
                'lock_modes' => $modes,
                'php_pid' => getmypid(),
                'pg_backend_pid' => $backendPid,
                'barrier_order' => ['legacy_locked_and_inserted', 'first_trust_blocked', 'legacy_committed'],
            ], JSON_THROW_ON_ERROR));
        } catch (RuntimeException $exception) {
            DB::rollBack();
            file_put_contents($arguments['result_file'], json_encode([
                'outcome' => 'rejected',
                'error_code' => $exception->getMessage(),
                'php_pid' => getmypid(),
                'pg_backend_pid' => $backendPid,
                'barrier_order' => ['first_trust_locked_and_inserted', 'b4c_blocked', 'first_trust_committed', 'b4c_rejected'],
            ], JSON_THROW_ON_ERROR));
        }
    }
} catch (Throwable $throwable) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    file_put_contents($arguments['result_file'], json_encode([
        'error' => $throwable::class.': '.$throwable->getMessage(),
        'php_pid' => getmypid(),
        'pg_backend_pid' => $backendPid,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP;
    }
}
