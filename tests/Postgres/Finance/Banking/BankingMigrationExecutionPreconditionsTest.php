<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationInventoryStatusEnum;
use Modules\Finance\Banking\Enums\BankingMigrationPilotAuthorizationStatusEnum;
use Modules\Finance\Banking\Enums\BankingMigrationPlanStatusEnum;
use Modules\Finance\Banking\Enums\BankingMigrationTargetIntakeStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationExceptionQuarantine;
use Modules\Finance\Banking\Models\BankingMigrationManifestEntry;
use Modules\Finance\Banking\Models\BankingMigrationPilotAuthorization;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class BankingMigrationExecutionPreconditionsTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;
    private User $viewOnlyActor;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function createFixtures(): void
    {
        $suffix = hash('sha256', microtime());

        $this->company = Company::create([
            'name' => 'Sprint34 Co ' . Str::random(6),
            'slug' => 'sprint34-co-' . Str::random(6),
            'is_active' => true,
        ]);
        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Sprint34 Property ' . Str::random(6),
            'slug' => 'sprint34-prop-' . Str::random(6),
            'code' => 'S34' . Str::random(4),
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Sprint34 Other Property ' . Str::random(6),
            'slug' => 'sprint34-other-' . Str::random(6),
            'code' => 'S34O' . Str::random(4),
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_VIEW, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_MANAGE, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.banking.migration.mapping.review', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.banking.migration.pilot.authorization.review', 'guard_name' => 'web']);

        $this->actor = User::create([
            'name' => 'Sprint34 Actor ' . Str::random(6),
            'email' => 'sprint34-actor-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->actor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->actor->givePermissionTo(BankingMigrationPlanService::PERMISSION_VIEW);
        setPermissionsTeamId($this->property->id);

        $this->viewOnlyActor = User::create([
            'name' => 'Sprint34 Viewer ' . Str::random(6),
            'email' => 'sprint34-viewer-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->viewOnlyActor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->viewOnlyActor->givePermissionTo(BankingMigrationPlanService::PERMISSION_VIEW);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createReviewAcceptedPilotAuthorization(
        Property $property,
        ?string $sourceUid = null,
        ?bool $targetActive = true,
        ?string $migrationPlanStatus = null
    ): array {
        $plan = BankingMigrationPlan::create([
            'property_id' => $property->id,
            'source_domain' => 'legacy_banking',
            'target_domain' => 'controlled_banking',
            'status' => $migrationPlanStatus ?? BankingMigrationPlanStatusEnum::DRY_RUN_COMPLETED,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => hash('sha256', 'test|' . Str::random(6)),
            'dry_run_metadata' => json_encode(['completed_at' => now()->toIso8601String()]),
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_actor_id' => $this->actor->id,
        ]);
        $plan->created_by = $this->actor->id;
        $plan->updated_by = $this->actor->id;
        $plan->save();

        $sourceUid = $sourceUid ?? (string) Str::ulid();
        $timestamp = now();

        DB::table('bank_accounts')->insert([
            'id' => $sourceUid,
            'property_id' => $property->id,
            'bank_name' => '__hidden_bank_name',
            'account_name' => '__hidden_account_name',
            'account_number' => '__hidden_account_number',
            'currency_code' => 'USD',
            'opening_balance' => 999.99,
            'current_balance' => 999.99,
            'reconciled_balance' => 999.99,
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $createdAtString = $timestamp->format('Y-m-d H:i:s');
        $updatedAtString = $timestamp->format('Y-m-d H:i:s');

        $sourceIdentityHash = hash('sha256', implode('|', [
            'legacy_banking', 'BankAccount', $sourceUid, $property->id,
        ]));

        $sourceSnapshotHash = hash('sha256', implode('|', [
            'BankAccount', $sourceUid, $property->id,
            $createdAtString, $updatedAtString,
        ]));

        $manifestEntry = BankingMigrationManifestEntry::create([
            'migration_plan_id' => $plan->id,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'source_ulid' => $sourceUid,
            'source_property_id' => $property->id,
            'source_identity_hash' => $sourceIdentityHash,
            'source_snapshot_hash' => $sourceSnapshotHash,
            'dry_run_version' => hash('sha256', 'test|' . $plan->id),
            'inventory_status' => BankingMigrationInventoryStatusEnum::INVENTORIED,
        ]);
        $manifestEntry->created_by = $this->actor->id;
        $manifestEntry->updated_by = $this->actor->id;
        $manifestEntry->save();

        $controlledAccountId = (string) Str::ulid();
        $glAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $property->id,
            'code' => 'GL-' . Str::random(6),
            'name' => 'Test GL Account',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_accounts')->insert([
            'id' => $controlledAccountId,
            'property_id' => $property->id,
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => '__hidden_controlled_bank_name',
            'account_name' => '__hidden_controlled_account_name',
            'external_account_reference' => '__hidden_ref',
            'currency_code' => 'USD',
            'is_active' => $targetActive ?? true,
            'source_reference' => 'test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'ctrl|' . $controlledAccountId),
            'source_snapshot' => json_encode([]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $targetIdentityHash = hash('sha256', implode('|', [
            'banking_migration_target_intake_v1', $property->id, $controlledAccountId,
        ]));

        $targetIntake = BankingMigrationTargetIntake::create([
            'property_id' => $property->id,
            'migration_plan_id' => $plan->id,
            'manifest_entry_id' => $manifestEntry->id,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'target_domain' => 'controlled_banking',
            'target_model' => 'ControlledBankAccount',
            'controlled_bank_account_id' => $controlledAccountId,
            'target_identity_hash' => $targetIdentityHash,
            'status' => BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED,
            'correlation_id' => (string) Str::ulid(),
            'proposal_actor_id' => $this->actor->id,
            'review_actor_id' => $this->viewOnlyActor->id,
            'review_outcome' => 'REVIEW_ACCEPTED',
            'review_timestamp' => now(),
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
        ]);
        $targetIntake->created_by = $this->actor->id;
        $targetIntake->updated_by = $this->actor->id;
        $targetIntake->save();

        $pilotAuth = BankingMigrationPilotAuthorization::create([
            'property_id' => $property->id,
            'migration_plan_id' => $plan->id,
            'manifest_entry_id' => $manifestEntry->id,
            'target_intake_id' => $targetIntake->id,
            'authorization_scope' => 'account_identity_pilot_only',
            'status' => BankingMigrationPilotAuthorizationStatusEnum::REVIEW_ACCEPTED,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => hash('sha256', 'pilot|' . Str::random(6)),
            'request_actor_id' => $this->actor->id,
            'review_actor_id' => $this->viewOnlyActor->id,
            'review_outcome' => 'REVIEW_ACCEPTED',
            'review_timestamp' => now(),
            'execution_authority' => 'MIGRATION_EXECUTION_NOT_IMPLEMENTED',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
        ]);
        $pilotAuth->created_by = $this->actor->id;
        $pilotAuth->updated_by = $this->actor->id;
        $pilotAuth->save();

        return [
            'plan' => $plan,
            'manifest_entry' => $manifestEntry,
            'target_intake' => $targetIntake,
            'pilot_authorization' => $pilotAuth,
            'source_ulid' => $sourceUid,
            'controlled_account_id' => $controlledAccountId,
        ];
    }

    private function propertySession(): array
    {
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ];
    }

    private function fullSnapshot(): array
    {
        return [
            'bank_accounts' => DB::table('bank_accounts')->count(),
            'bank_statement_lines' => DB::table('bank_statement_lines')->count(),
            'reconciliation_matches' => DB::table('reconciliation_matches')->count(),
            'reconciliation_sessions' => DB::table('reconciliation_sessions')->count(),
            'controlled_bank_accounts' => DB::table('controlled_bank_accounts')->count(),
            'controlled_bank_statement_lines' => DB::table('controlled_bank_statement_lines')->count(),
            'bank_payment_reconciliations' => DB::table('bank_payment_reconciliations')->count(),
            'banking_migration_plans' => DB::table('banking_migration_plans')->count(),
            'banking_migration_manifest_entries' => DB::table('banking_migration_manifest_entries')->count(),
            'banking_migration_exception_quarantines' => DB::table('banking_migration_exception_quarantines')->count(),
            'banking_migration_target_intakes' => DB::table('banking_migration_target_intakes')->count(),
            'banking_migration_pilot_authorizations' => DB::table('banking_migration_pilot_authorizations')->count(),
            'payment_executions' => DB::table('payment_executions')->count(),
        ];
    }

    public function test_unauthenticated_access_is_denied(): void
    {
        $this->createFixtures();

        $this->get(route('finance.banking.migration.index'))
            ->assertRedirect();
    }

    public function test_active_property_context_required(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId(null);

        $user = User::create([
            'name' => 'EP NoProp ' . Str::random(6),
            'email' => 'ep-noprop-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->givePermissionTo(BankingMigrationPlanService::PERMISSION_VIEW);
        setPermissionsTeamId($this->property->id);

        $response = $this->actingAs($user, 'web')
            ->get(route('finance.banking.migration.index'));

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_view_permission_is_required(): void
    {
        $this->createFixtures();

        $user = User::create([
            'name' => 'EP NoPerm ' . Str::random(6),
            'email' => 'ep-noperm-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($user, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertFalse($props['permissions']['can_view']);
        $this->assertEmpty($props['execution_preconditions'] ?? []);
    }

    public function test_cross_property_precondition_evidence_fails_closed(): void
    {
        $this->createFixtures();

        $fixtures = $this->createReviewAcceptedPilotAuthorization($this->otherProperty);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertEmpty($preconditions, 'Cross-property pilot authorization evidence must not leak.');
    }

    public function test_workspace_request_remains_read_only(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        $before = $this->fullSnapshot();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $after = $this->fullSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count, $after[$table], "Table '{$table}' mutated during workspace request.");
        }
    }

    public function test_workspace_does_not_mutate_legacy_banking_records(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        $beforeAccount = DB::table('bank_accounts')->count();
        $beforeSession = DB::table('reconciliation_sessions')->count();
        $beforeMatch = DB::table('reconciliation_matches')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeAccount, DB::table('bank_accounts')->count());
        $this->assertSame($beforeSession, DB::table('reconciliation_sessions')->count());
        $this->assertSame($beforeMatch, DB::table('reconciliation_matches')->count());
    }

    public function test_workspace_does_not_mutate_controlled_banking_records(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        $beforeControlled = DB::table('controlled_bank_accounts')->count();
        $beforeStatement = DB::table('controlled_bank_statement_lines')->count();
        $beforeRecon = DB::table('bank_payment_reconciliations')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeControlled, DB::table('controlled_bank_accounts')->count());
        $this->assertSame($beforeStatement, DB::table('controlled_bank_statement_lines')->count());
        $this->assertSame($beforeRecon, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_workspace_does_not_mutate_control_plane_records(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        $beforePlans = DB::table('banking_migration_plans')->count();
        $beforeEntries = DB::table('banking_migration_manifest_entries')->count();
        $beforeQuarantines = DB::table('banking_migration_exception_quarantines')->count();
        $beforeIntakes = DB::table('banking_migration_target_intakes')->count();
        $beforePilotAuths = DB::table('banking_migration_pilot_authorizations')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforePlans, DB::table('banking_migration_plans')->count());
        $this->assertSame($beforeEntries, DB::table('banking_migration_manifest_entries')->count());
        $this->assertSame($beforeQuarantines, DB::table('banking_migration_exception_quarantines')->count());
        $this->assertSame($beforeIntakes, DB::table('banking_migration_target_intakes')->count());
        $this->assertSame($beforePilotAuths, DB::table('banking_migration_pilot_authorizations')->count());
    }

    public function test_workspace_does_not_mutate_payment_execution_or_journal(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        $beforePayments = DB::table('payment_executions')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforePayments, DB::table('payment_executions')->count());
    }

    public function test_source_scope_is_projected_as_bank_account_only(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('BANK_ACCOUNT_ONLY', $precon['manifest_source_scope']);
        }
    }

    public function test_source_snapshot_uses_no_prohibited_fields(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('UNCHANGED', $precon['manifest_source_snapshot']);
        }

        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('__hidden_bank_name', $responseContent);
        $this->assertStringNotContainsString('__hidden_account_name', $responseContent);
        $this->assertStringNotContainsString('__hidden_account_number', $responseContent);
        $this->assertStringNotContainsString('__hidden_controlled_bank_name', $responseContent);
        $this->assertStringNotContainsString('__hidden_controlled_account_name', $responseContent);
        $this->assertStringNotContainsString('__hidden_ref', $responseContent);
    }

    public function test_legacy_controlled_fields_are_never_compared(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('999.99', $responseContent);
        $this->assertStringNotContainsString('balance', $responseContent);
    }

    public function test_no_target_selection_ranking_candidate_score_or_confidence_exists(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertArrayNotHasKey('target_match', $precon);
            $this->assertArrayNotHasKey('best_match', $precon);
            $this->assertArrayNotHasKey('execution_ready', $precon);
            $this->assertArrayNotHasKey('migration_ready', $precon);
            $this->assertArrayNotHasKey('pilot_ready', $precon);
            $this->assertArrayNotHasKey('approved_for_execution', $precon);
            $this->assertArrayNotHasKey('migratable', $precon);
        }

        $this->assertSame('EXECUTION_IMPLEMENTATION_DEFERRED', $preconditions[0]['summary_status']);
    }

    public function test_quarantine_state_is_projected_without_exposing_financial_content(): void
    {
        $this->createFixtures();
        $fixtures = $this->createReviewAcceptedPilotAuthorization($this->property);

        BankingMigrationExceptionQuarantine::create([
            'migration_plan_id' => $fixtures['plan']->id,
            'manifest_entry_id' => $fixtures['manifest_entry']->id,
            'exception_code' => \Modules\Finance\Banking\Enums\BankingMigrationExceptionCodeEnum::DUPLICATE_SOURCE_IDENTITY,
            'severity' => \Modules\Finance\Banking\Enums\BankingMigrationExceptionSeverityEnum::WARNING,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'source_ulid' => $fixtures['source_ulid'],
            'source_property_id' => $this->property->id,
            'correlation_id' => (string) Str::ulid(),
            'is_resolved' => false,
        ]);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('BLOCKED', $precon['exception_quarantine_state']);
        }

        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('DUPLICATE_SOURCE_IDENTITY', $responseContent);
    }

    public function test_target_intake_review_status_is_server_projected(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('REVIEW_ACCEPTED', $precon['target_intake_review_state']);
        }
    }

    public function test_pilot_authorization_review_status_is_server_projected(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('REVIEW_ACCEPTED', $precon['pilot_auth_review_state']);
        }
    }

    public function test_controlled_target_active_state_is_server_projected(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property, null, true);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('ACTIVE', $precon['target_operational_state']);
        }
    }

    public function test_inactive_target_is_projected_as_inactive(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property, null, false);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('INACTIVE', $precon['target_operational_state']);
        }
    }

    public function test_property_boundary_is_server_projected(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('VALID', $precon['property_boundary']);
        }
    }

    public function test_future_lineage_contract_is_architecture_defined(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('ARCHITECTURE_DEFINED', $precon['future_lineage_contract']);
        }
    }

    public function test_execution_remains_not_implemented(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('NOT_IMPLEMENTED', $precon['future_execution_permission']);
        }
    }

    public function test_cutover_remains_not_authorized(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('NOT_AUTHORIZED', $precon['future_cutover_permission']);
        }
    }

    public function test_no_execution_cutover_correction_or_rollback_route_exists(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $base = '/finance/banking/migration';

        $nonexistentRoutes = [
            $base . '/execute',
            $base . '/pilot-execute',
            $base . '/pilot-authorization/execute',
            $base . '/cutover',
            $base . '/rollback',
            $base . '/correct',
            $base . '/correction',
            $base . '/plan/migrate',
            $base . '/plan/execute',
        ];

        foreach ($nonexistentRoutes as $url) {
            $response = $this->withSession($this->propertySession())
                ->actingAs($this->actor, 'web')
                ->post($url);

            $this->assertNotEquals(200, $response->getStatusCode(), "Route {$url} should not exist but returned 200.");
        }
    }

    public function test_no_confirmation_intent_is_created_or_consumed(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertArrayNotHasKey('confirmation_required', $props);
        $this->assertArrayNotHasKey('confirmation_intent', $props);
    }

    public function test_source_snapshot_changed_is_projected(): void
    {
        $this->createFixtures();
        $fixtures = $this->createReviewAcceptedPilotAuthorization($this->property);

        DB::table('bank_accounts')
            ->where('id', $fixtures['source_ulid'])
            ->update(['updated_at' => now()->addHour()]);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('CHANGED', $precon['manifest_source_snapshot']);
        }
    }

    public function test_summary_status_is_execution_implementation_deferred(): void
    {
        $this->createFixtures();
        $this->createReviewAcceptedPilotAuthorization($this->property);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];
        $this->assertNotEmpty($preconditions);

        foreach ($preconditions as $precon) {
            $this->assertSame('EXECUTION_IMPLEMENTATION_DEFERRED', $precon['summary_status']);
        }
    }

    public function test_execution_preconditions_are_empty_when_no_pilot_auths_exist(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertEmpty($props['execution_preconditions'] ?? []);
    }
}
