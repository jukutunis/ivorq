<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationPlanStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingMigrationControlPlaneTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;
    private User $viewOnlyActor;
    private User $otherPropertyActor;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_unauthenticated_access_is_denied(): void
    {
        $this->createFixtures();

        $this->get(route('finance.banking.migration.index'))
            ->assertRedirect();
    }

    public function test_view_permission_required_for_workspace(): void
    {
        $this->createFixtures();

        $user = User::create([
            'name' => 'NoPerm User ' . Str::random(6),
            'email' => 'noperm-' . Str::random(6) . '@example.test',
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
        $this->assertEmpty($props['plans']);
    }

    public function test_manage_permission_required_for_plan_creation(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->viewOnlyActor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'test-request-' . Str::random(6),
            ])
            ->assertForbidden();
    }

    public function test_active_property_context_required(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        app(CurrentPropertyService::class)->setPropertyId(null);

        $user = User::create([
            'name' => 'NoProp User ' . Str::random(6),
            'email' => 'noprop-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->givePermissionTo(BankingMigrationPlanService::PERMISSION_MANAGE);

        $response = $this->actingAs($user, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'test-request-' . Str::random(6),
            ]);

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_property_is_server_resolved(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $identity = 'server-resolved-prop-' . Str::random(6);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => $identity,
            ])
            ->assertRedirect();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame($this->property->id, $plan->property_id);
    }

    public function test_browser_injected_property_does_not_cross_scope(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $beforePlans = BankingMigrationPlan::where('property_id', $this->otherProperty->id)->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create') . '?property_id=' . $this->otherProperty->id, [
                'request_identity' => 'browser-injected-prop-' . Str::random(6),
            ])
            ->assertRedirect();

        $afterPlans = BankingMigrationPlan::where('property_id', $this->otherProperty->id)->count();
        $this->assertSame($beforePlans, $afterPlans, 'Browser-injected property should not affect plan scope.');

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);
    }

    public function test_cross_property_visibility_fails_closed(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPlanService::class);
        $service->createPlan('plan-in-prop-' . Str::random(6), $this->actor);

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertEmpty($props['plans']);
    }

    public function test_cross_property_action_fails_closed(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $service = new \Modules\Finance\Banking\Services\BankingMigrationPlanService();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $plan = $service->createPlan('plan-for-cross-prop-' . Str::random(6), $this->actor);

        $beforeStatus = $plan->status;

        app(CurrentPropertyService::class)->setPropertyId(null);

        setPermissionsTeamId($this->otherProperty->id);

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.request-dry-run', ['plan' => $plan->id]));

        $response->assertRedirect();

        $plan->refresh();
        $this->assertSame($beforeStatus, $plan->status);
    }

    public function test_plan_creation_writes_only_control_plane_tables(): void
    {
        $this->createFixtures();

        $before = $this->fullSnapshot();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'write-only-cp-' . Str::random(6),
            ])
            ->assertRedirect();

        $after = $this->fullSnapshot();

        foreach ($before as $table => $count) {
            if ($table === 'banking_migration_plans') {
                $this->assertSame($count + 1, $after[$table], "Migration plan table should gain one row.");
            } else {
                $this->assertSame($count, $after[$table], "Table '{$table}' mutated during plan creation.");
            }
        }
    }

    public function test_plan_creation_does_not_modify_legacy_banking(): void
    {
        $this->createFixtures();

        $beforeLegacyAccountCount = DB::table('bank_accounts')->count();
        $beforeLegacySessionCount = DB::table('reconciliation_sessions')->count();
        $beforeLegacyMatchCount = DB::table('reconciliation_matches')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'no-legacy-mod-' . Str::random(6),
            ])
            ->assertRedirect();

        $this->assertSame($beforeLegacyAccountCount, DB::table('bank_accounts')->count());
        $this->assertSame($beforeLegacySessionCount, DB::table('reconciliation_sessions')->count());
        $this->assertSame($beforeLegacyMatchCount, DB::table('reconciliation_matches')->count());
    }

    public function test_plan_creation_does_not_modify_controlled_banking(): void
    {
        $this->createFixtures();

        $beforeControlledAccountCount = DB::table('controlled_bank_accounts')->count();
        $beforeControlledStmtCount = DB::table('controlled_bank_statement_lines')->count();
        $beforeReconciliationCount = DB::table('bank_payment_reconciliations')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'no-controlled-mod-' . Str::random(6),
            ])
            ->assertRedirect();

        $this->assertSame($beforeControlledAccountCount, DB::table('controlled_bank_accounts')->count());
        $this->assertSame($beforeControlledStmtCount, DB::table('controlled_bank_statement_lines')->count());
        $this->assertSame($beforeReconciliationCount, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_plan_creation_does_not_modify_payment_execution_or_journal(): void
    {
        $this->createFixtures();

        $beforePaymentExecutions = DB::table('payment_executions')->count();
        $beforeJournals = DB::table('gl_journal_entries')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'no-pe-mod-' . Str::random(6),
            ])
            ->assertRedirect();

        $this->assertSame($beforePaymentExecutions, DB::table('payment_executions')->count());
        $this->assertSame($beforeJournals, DB::table('gl_journal_entries')->count());
    }

    public function test_source_and_target_domains_are_fixed_server_side(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'fixed-domains-' . Str::random(6),
                'source_domain' => 'attacker_domain',
                'target_domain' => 'evil_domain',
            ])
            ->assertRedirect();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame('legacy_banking', $plan->source_domain);
        $this->assertSame('controlled_banking', $plan->target_domain);
    }

    public function test_cutover_authority_is_always_not_authorized(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'cutover-check-' . Str::random(6),
                'cutover_authority' => 'AUTHORIZED',
            ])
            ->assertRedirect();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $plan->cutover_authority);
    }

    public function test_execution_authority_is_always_unavailable(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'exec-check-' . Str::random(6),
                'execution_authority' => 'AVAILABLE',
            ])
            ->assertRedirect();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame('UNAVAILABLE', $plan->execution_authority);
    }

    public function test_idempotency_is_preserved(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPlanService::class);

        $identity = 'idempotent-request-' . Str::random(6);

        $plan1 = $service->createPlan($identity, $this->actor);
        $plan2 = $service->createPlan($identity, $this->actor);

        $this->assertSame($plan1->id, $plan2->id);
        $this->assertSame(1, BankingMigrationPlan::where('idempotency_key', $plan1->idempotency_key)->count());
    }

    public function test_audit_correlation_evidence_exists(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'audit-evidence-' . Str::random(6),
            ])
            ->assertRedirect();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);
        $this->assertNotEmpty($plan->correlation_id);
        $this->assertNotEmpty($plan->idempotency_key);
        $this->assertNotEmpty($plan->created_actor_id);
        $this->assertNotNull($plan->created_at);
        $this->assertNotNull($plan->updated_at);
    }

    public function test_no_execution_route_exists_in_workspace(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('EXECUTING', $content);
        $this->assertStringNotContainsString('EXECUTED', $content);
        $this->assertStringNotContainsString('CUTOVER_READY', $content);
        $this->assertStringNotContainsString('CUTOVER_COMPLETE', $content);
        $this->assertStringNotContainsString('Execute Migration', $content);
        $this->assertStringNotContainsString('Cutover', $content);
    }

    public function test_no_confirmation_intent_is_created_or_consumed(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'no-confirmation-' . Str::random(6),
            ])
            ->assertRedirect();

        $content = $response->getContent();
        $this->assertStringNotContainsString('sensitive-action-confirmation', $content);
    }

    public function test_no_unrelated_role_or_permission_changes(): void
    {
        $this->createFixtures();

        $beforeRoles = DB::table('roles')->count();
        $beforePermissionCount = DB::table('permissions')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'role-check-' . Str::random(6),
            ])
            ->assertRedirect();

        $this->assertSame($beforeRoles, DB::table('roles')->count());
        $this->assertSame($beforePermissionCount, DB::table('permissions')->count());
    }

    public function test_plan_status_starts_as_draft(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPlanService::class);
        $plan = $service->createPlan('draft-status-' . Str::random(6), $this->actor);

        $this->assertSame(BankingMigrationPlanStatusEnum::DRAFT, $plan->status);
    }

    public function test_dry_run_request_transitions_draft_to_requested(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPlanService::class);
        $plan = $service->createPlan('dr-request-' . Str::random(6), $this->actor);

        $updated = $service->requestDryRun($plan->id, $this->actor);

        $this->assertSame(BankingMigrationPlanStatusEnum::DRY_RUN_REQUESTED, $updated->status);
    }

    public function test_dry_run_request_from_non_draft_fails(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPlanService::class);
        $plan = $service->createPlan('dr-fail-' . Str::random(6), $this->actor);
        $service->requestDryRun($plan->id, $this->actor);

        $this->expectException(\DomainException::class);
        $service->requestDryRun($plan->id, $this->actor);
    }

    public function test_workspace_projection_is_non_financial(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('opening_balance', $content);
        $this->assertStringNotContainsString('current_balance', $content);
        $this->assertStringNotContainsString('reconciled_balance', $content);
        $this->assertStringNotContainsString('amount', $content);
        $this->assertStringNotContainsString('account_number', $content);
        $this->assertStringNotContainsString('confidence_score', $content);
    }

    public function test_browser_injected_status_is_ignored(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'status-inject-' . Str::random(6),
                'status' => 'APPROVED_FOR_EXECUTION',
            ])
            ->assertRedirect();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame(BankingMigrationPlanStatusEnum::DRAFT, $plan->status);
    }

    public function test_browser_injected_actor_is_ignored(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'actor-inject-' . Str::random(6),
                'created_actor_id' => (string) Str::ulid(),
            ])
            ->assertRedirect();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame($this->actor->id, $plan->created_actor_id);
    }

    public function test_view_only_actor_cannot_create_plan(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->viewOnlyActor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'view-only-' . Str::random(6),
            ])
            ->assertForbidden();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNull($plan);
    }

    public function test_finance_controller_can_view_migration_plan(): void
    {
        $this->createFixtures();

        $this->createPlanForTest('fc-view');

        $planCount = BankingMigrationPlan::where('property_id', $this->property->id)->count();
        $this->assertGreaterThan(0, $planCount, 'Plan should exist in DB before controller request.');

        $fcUser = $this->createFinanceControllerUser();

        setPermissionsTeamId($this->property->id);
        $this->assertTrue($fcUser->can(BankingMigrationPlanService::PERMISSION_VIEW), 'FC user should have view permission.');

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPlanService::class);
        $plans = $service->listForProperty($this->property->id);
        $this->assertNotEmpty($plans, 'Service should list plans for the property.');

        $response = $this->withSession($this->propertySession())
            ->actingAs($fcUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertTrue($props['permissions']['can_view'], 'Controller should return can_view=true.');
        $this->assertNotEmpty($props['plans'], 'Controller should return non-empty plans array.');
    }

    public function test_finance_controller_cannot_create_migration_plan(): void
    {
        $this->createFixtures();

        $fcUser = $this->createFinanceControllerUser();

        setPermissionsTeamId($this->property->id);

        $beforeCount = BankingMigrationPlan::where('property_id', $this->property->id)->count();

        $this->withSession($this->propertySession())
            ->actingAs($fcUser, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'fc-create-' . Str::random(6),
            ])
            ->assertForbidden();

        $this->assertSame($beforeCount, BankingMigrationPlan::where('property_id', $this->property->id)->count());
    }

    public function test_finance_controller_cannot_request_dry_run(): void
    {
        $this->createFixtures();

        $plan = $this->createPlanForTest('fc-dr');

        $fcUser = $this->createFinanceControllerUser();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($fcUser, 'web')
            ->post(route('finance.banking.migration.plan.request-dry-run', ['plan' => $plan->id]))
            ->assertForbidden();
    }

    public function test_finance_manager_can_view_and_create_plan(): void
    {
        $this->createFixtures();

        $fmUser = $this->createFinanceManagerUser();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($fmUser, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'fm-create-' . Str::random(6),
            ])
            ->assertRedirect();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);

        $response = $this->withSession($this->propertySession())
            ->actingAs($fmUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertTrue($props['permissions']['can_view']);
        $this->assertTrue($props['permissions']['can_manage']);
    }

    public function test_finance_manager_can_request_dry_run(): void
    {
        $this->createFixtures();

        $plan = $this->createPlanForTest('fm-dr');

        $fmUser = $this->createFinanceManagerUser();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->assertSame(BankingMigrationPlanStatusEnum::DRAFT, $plan->status);

        $this->withSession($this->propertySession())
            ->actingAs($fmUser, 'web')
            ->post(route('finance.banking.migration.plan.request-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $plan->refresh();
        $this->assertSame(BankingMigrationPlanStatusEnum::DRY_RUN_REQUESTED, $plan->status);
    }

    public function test_finance_manager_remains_property_scoped(): void
    {
        $this->createFixtures();

        $plan = $this->createPlanForTest('fm-prop');

        $fmUser = $this->createFinanceManagerUser();

        setPermissionsTeamId($this->otherProperty->id);

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($fmUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertEmpty($props['plans']);
    }

    public function test_finance_controller_remains_property_scoped(): void
    {
        $this->createFixtures();

        $this->createPlanForTest('fc-prop');

        $fcUser = $this->createFinanceControllerUser();

        setPermissionsTeamId($this->otherProperty->id);

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($fcUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertEmpty($props['plans']);
    }

    public function test_general_ledger_accountant_cannot_manage_migration(): void
    {
        $this->createFixtures();

        $glUser = $this->createGeneralLedgerAccountantUser();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($glUser, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'gl-create-' . Str::random(6),
            ])
            ->assertForbidden();

        $response = $this->withSession($this->propertySession())
            ->actingAs($glUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertFalse($props['permissions']['can_view']);
        $this->assertFalse($props['permissions']['can_manage']);
    }

    public function test_general_cashier_has_no_migration_authority(): void
    {
        $this->createFixtures();

        $cashier = User::create([
            'name' => 'MigCP Cashier ' . Str::random(6),
            'email' => 'mig-cp-cashier-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $cashier->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($cashier, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertFalse($props['permissions']['can_view']);
        $this->assertFalse($props['permissions']['can_manage']);

        $this->withSession($this->propertySession())
            ->actingAs($cashier, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'cashier-create-' . Str::random(6),
            ])
            ->assertForbidden();
    }

    public function test_super_admin_retains_existing_behavior(): void
    {
        $this->createFixtures();

        $saUser = User::create([
            'name' => 'MigCP SuperAdmin ' . Str::random(6),
            'email' => 'mig-cp-sa-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $saUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $saUser->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            BankingMigrationPlanService::PERMISSION_MANAGE,
        ]);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($saUser, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'sa-create-' . Str::random(6),
            ])
            ->assertRedirect();

        $plan = BankingMigrationPlan::where('property_id', $this->property->id)->first();
        $this->assertNotNull($plan);
    }

    public function test_no_unrelated_role_assignments_changed(): void
    {
        $this->createFixtures();

        $beforeRoleAssignmentCount = DB::table('model_has_permissions')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.create'), [
                'request_identity' => 'unrelated-role-' . Str::random(6),
            ])
            ->assertRedirect();

        $this->assertSame($beforeRoleAssignmentCount, DB::table('model_has_permissions')->count());
    }

    private function createFinanceControllerUser(): User
    {
        $user = User::create([
            'name' => 'MigCP FC ' . Str::random(6),
            'email' => 'mig-cp-fc-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->givePermissionTo(BankingMigrationPlanService::PERMISSION_VIEW);

        return $user;
    }

    private function createFinanceManagerUser(): User
    {
        $user = User::create([
            'name' => 'MigCP FM ' . Str::random(6),
            'email' => 'mig-cp-fm-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            BankingMigrationPlanService::PERMISSION_MANAGE,
        ]);

        return $user;
    }

    private function createGeneralLedgerAccountantUser(): User
    {
        $user = User::create([
            'name' => 'MigCP GL ' . Str::random(6),
            'email' => 'mig-cp-gl-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        return $user;
    }

    private function createPlanForTest(string $prefix): BankingMigrationPlan
    {
        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPlanService::class);

        return $service->createPlan($prefix . '-' . Str::random(6), $this->actor);
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'MigCP Company ' . $companySuffix,
            'slug' => 'mig-cp-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'MigCP Property ' . $companySuffix,
            'slug' => 'mig-cp-property-' . $companySuffix,
            'code' => 'MCP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'MigCP Other ' . $companySuffix,
            'slug' => 'mig-cp-other-' . $companySuffix,
            'code' => 'MCO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_VIEW, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_MANAGE, 'guard_name' => 'web']);

        $this->actor = User::create([
            'name' => 'MigCP Actor ' . $companySuffix,
            'email' => 'mig-cp-actor-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->actor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->actor->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->actor->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            BankingMigrationPlanService::PERMISSION_MANAGE,
        ]);

        $this->viewOnlyActor = User::create([
            'name' => 'MigCP ViewOnly ' . $companySuffix,
            'email' => 'mig-cp-viewonly-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->viewOnlyActor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->viewOnlyActor->givePermissionTo(BankingMigrationPlanService::PERMISSION_VIEW);

        $this->otherPropertyActor = User::create([
            'name' => 'MigCP OtherProp ' . $companySuffix,
            'email' => 'mig-cp-otherprop-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->otherPropertyActor->properties()->attach($this->otherProperty->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $timestamp = now();

        $legacyBankAccountId = (string) Str::ulid();
        DB::table('bank_accounts')->insert([
            'id' => $legacyBankAccountId,
            'property_id' => $this->property->id,
            'bank_name' => 'MigCP Legacy Bank',
            'account_name' => 'Legacy Account',
            'account_number' => 'LEGACY-' . $companySuffix,
            'currency_code' => 'IDR',
            'opening_balance' => '500.00',
            'current_balance' => '500.00',
            'reconciled_balance' => '0.00',
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('reconciliation_sessions')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'bank_account_id' => $legacyBankAccountId,
            'statement_date_start' => '2026-06-01',
            'statement_date_end' => '2026-06-30',
            'opening_balance' => '500.00',
            'reconciled_balance' => '0.00',
            'unreconciled_balance' => '500.00',
            'status' => 'InProgress',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $glAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $this->property->id,
            'code' => 'MCP-GL-' . $companySuffix,
            'name' => 'MigCP GL Account',
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

        $controlledBankAccountId = (string) Str::ulid();
        DB::table('controlled_bank_accounts')->insert([
            'id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => 'MigCP Controlled Bank',
            'account_name' => 'Controlled Account',
            'external_account_reference' => 'MCP-EXT-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'mig-cp-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'mig-cp-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'migration_control_plane']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => (string) Str::ulid(),
            'controlled_bank_account_id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'mcp-stmt-' . $companySuffix,
            'external_reference' => 'MCP-STMT-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => 'OUTFLOW',
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'mcp-stmt-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'migration_control_plane']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function propertySession(): array
    {
        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ];
    }

    private function otherPropertySession(): array
    {
        return [
            'active_property_id' => $this->otherProperty->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->otherProperty->id,
        ];
    }

    private function fullSnapshot(): array
    {
        $tables = [
            'banking_migration_plans',
            'controlled_bank_accounts',
            'controlled_bank_statement_lines',
            'bank_accounts',
            'bank_statement_lines',
            'reconciliation_matches',
            'reconciliation_sessions',
            'bank_payment_reconciliations',
            'payment_executions',
            'cashbook_transactions',
            'cashier_sessions',
            'cashier_payment_instruments',
            'gl_journal_entries',
            'gl_accounts',
            'payment_proposals',
            'payment_proposal_items',
            'journal_candidates',
            'permissions',
            'roles',
            'users',
            'companies',
            'properties',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        return $snapshot;
    }
}
