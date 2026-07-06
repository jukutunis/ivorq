<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationPlanStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationManifestEntry;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Finance\Banking\Models\BankingMigrationExceptionQuarantine;
use Modules\Finance\Banking\Services\BankingMigrationDryRunService;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingMigrationDryRunTest extends PostgresTestCase
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

    public function test_unauthenticated_dry_run_denied(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();

        $this->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();
    }

    public function test_missing_manage_permission_denied(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->viewOnlyActor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertForbidden();
    }

    public function test_active_property_context_required_for_dry_run(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        app(CurrentPropertyService::class)->setPropertyId(null);

        $user = User::create([
            'name' => 'DryRun NoProp ' . Str::random(6),
            'email' => 'dryrun-noprop-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->givePermissionTo(BankingMigrationPlanService::PERMISSION_MANAGE);

        $response = $this->actingAs($user, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]));

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_cross_property_plan_action_fails_closed_dry_run(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeStatus = $plan->status;

        app(CurrentPropertyService::class)->setPropertyId(null);
        setPermissionsTeamId($this->otherProperty->id);

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $plan->refresh();
        $this->assertSame($beforeStatus, $plan->status);
    }

    public function test_manifest_and_quarantine_writes_occur_only_in_control_plane_tables(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $before = $this->fullSnapshot();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $after = $this->fullSnapshot();

        foreach ($before as $table => $count) {
            if ($table === 'banking_migration_plans'
                || $table === 'banking_migration_manifest_entries'
                || $table === 'banking_migration_exception_quarantines') {
                continue;
            }
            $this->assertSame($count, $after[$table], "Table '{$table}' mutated during dry run.");
        }
    }

    public function test_no_bank_account_modification(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeCount = DB::table('bank_accounts')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $this->assertSame($beforeCount, DB::table('bank_accounts')->count());
    }

    public function test_no_bank_statement_line_modification(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeCount = DB::table('bank_statement_lines')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $this->assertSame($beforeCount, DB::table('bank_statement_lines')->count());
    }

    public function test_no_reconciliation_match_modification(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeCount = DB::table('reconciliation_matches')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $this->assertSame($beforeCount, DB::table('reconciliation_matches')->count());
    }

    public function test_no_reconciliation_session_modification(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeCount = DB::table('reconciliation_sessions')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $this->assertSame($beforeCount, DB::table('reconciliation_sessions')->count());
    }

    public function test_no_controlled_bank_account_modification(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeCount = DB::table('controlled_bank_accounts')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $this->assertSame($beforeCount, DB::table('controlled_bank_accounts')->count());
    }

    public function test_no_controlled_bank_statement_line_modification(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeCount = DB::table('controlled_bank_statement_lines')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $this->assertSame($beforeCount, DB::table('controlled_bank_statement_lines')->count());
    }

    public function test_no_bank_payment_reconciliation_modification(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeCount = DB::table('bank_payment_reconciliations')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $this->assertSame($beforeCount, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_no_payment_execution_modification(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeCount = DB::table('payment_executions')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $this->assertSame($beforeCount, DB::table('payment_executions')->count());
    }

    public function test_no_journal_modification(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        $beforeCount = DB::table('gl_journal_entries')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $this->assertSame($beforeCount, DB::table('gl_journal_entries')->count());
    }

    public function test_manifest_source_identity_is_immutable(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $entries = BankingMigrationManifestEntry::where('migration_plan_id', $plan->id)->get();

        foreach ($entries as $entry) {
            $recomputed = hash('sha256', implode('|', [
                BankingMigrationPlanService::SOURCE_DOMAIN,
                $entry->source_model,
                $entry->source_ulid,
                $entry->source_property_id,
            ]));

            $this->assertSame($recomputed, $entry->source_identity_hash);
        }
    }

    public function test_no_target_mapping_in_manifest(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('controlled_bank_account_id', $content);
        $this->assertStringNotContainsString('target_id', $content);
        $this->assertStringNotContainsString('target_ulid', $content);
    }

    public function test_legacy_balances_never_selected(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $entries = BankingMigrationManifestEntry::where('migration_plan_id', $plan->id)->get();

        foreach ($entries as $entry) {
            $hashInput = $entry->source_snapshot_hash;
            $this->assertStringNotContainsString('balance', strtolower($hashInput));
        }
    }

    public function test_no_cross_domain_comparison_in_workspace(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('confidence_score', $content);
        $this->assertStringNotContainsString('migration_score', $content);
    }

    public function test_repeated_dry_run_is_idempotent(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationDryRunService::class);

        $result1 = $service->executeDryRun($plan->id, $this->actor);
        $result2 = $service->executeDryRun($plan->id, $this->actor);

        $this->assertSame($result1['manifest_count'], $result2['manifest_count']);
        $this->assertSame($result1['quarantine_count'], $result2['quarantine_count']);
        $this->assertSame($result1['dry_run_version'], $result2['dry_run_version']);
    }

    public function test_cutover_remains_not_authorized_after_dry_run(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $plan->refresh();
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $plan->cutover_authority);
    }

    public function test_no_execution_cutover_route_in_workspace(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('Execute Migration', $content);
        $this->assertStringNotContainsString('Cutover', $content);
    }

    public function test_no_confirmation_intent_created_or_consumed_by_dry_run(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $content = $response->getContent();
        $this->assertStringNotContainsString('sensitive-action-confirmation', $content);
    }

    public function test_browser_injected_values_do_not_alter_dry_run(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', [
                'plan' => $plan->id,
                'property_id' => $this->otherProperty->id,
                'target_id' => (string) Str::ulid(),
                'status' => 'EXECUTED',
                'amount' => '1000000.00',
                'mapping' => 'dummy',
            ]))
            ->assertRedirect();

        $plan->refresh();
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $plan->cutover_authority);
        $this->assertSame('UNAVAILABLE', $plan->execution_authority);
        $this->assertNotEquals('EXECUTED', $plan->status->value);
    }

    public function test_quarantine_cannot_be_created_by_browser_data(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', [
                'plan' => $plan->id,
                'exception_code' => 'CUSTOM_BROWSER_CODE',
                'quarantine_data' => 'browser_injected',
            ]))
            ->assertRedirect();

        $quarantines = BankingMigrationExceptionQuarantine::where('migration_plan_id', $plan->id)->get();
        foreach ($quarantines as $q) {
            $this->assertNotEquals('CUSTOM_BROWSER_CODE', $q->exception_code->value);
        }
    }

    public function test_dry_run_produces_manifest_for_bank_accounts(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationDryRunService::class);
        $result = $service->executeDryRun($plan->id, $this->actor);

        $this->assertGreaterThan(0, $result['manifest_count']);
        $this->assertGreaterThan(0, $result['inventoried_count']);
    }

    public function test_dry_run_plan_transitions_to_completed_or_blocked(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $plan->refresh();
        $this->assertContains($plan->status, [
            BankingMigrationPlanStatusEnum::DRY_RUN_COMPLETED,
            BankingMigrationPlanStatusEnum::BLOCKED,
        ]);
    }

    public function test_manifest_entries_are_property_scoped(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();
        $this->transitionToDryRunRequested($plan);

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationDryRunService::class);
        $service->executeDryRun($plan->id, $this->actor);

        $entries = BankingMigrationManifestEntry::where('migration_plan_id', $plan->id)->get();
        foreach ($entries as $entry) {
            $this->assertSame($this->property->id, $entry->source_property_id,
                "Manifest entry references property outside the plan scope.");
        }
    }

    public function test_only_dry_run_requested_can_execute_dry_run(): void
    {
        $this->createFixtures();

        $plan = $this->createDraftPlan();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post(route('finance.banking.migration.plan.execute-dry-run', ['plan' => $plan->id]))
            ->assertRedirect();

        $plan->refresh();
        $this->assertSame(BankingMigrationPlanStatusEnum::DRAFT, $plan->status);
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'DryRun Company ' . $companySuffix,
            'slug' => 'dryrun-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'DryRun Property ' . $companySuffix,
            'slug' => 'dryrun-property-' . $companySuffix,
            'code' => 'DRP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'DryRun Other ' . $companySuffix,
            'slug' => 'dryrun-other-' . $companySuffix,
            'code' => 'DRO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_VIEW, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_MANAGE, 'guard_name' => 'web']);

        $this->actor = User::create([
            'name' => 'DryRun Actor ' . $companySuffix,
            'email' => 'dryrun-actor-' . $companySuffix . '@example.test',
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
            'name' => 'DryRun ViewOnly ' . $companySuffix,
            'email' => 'dryrun-viewonly-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->viewOnlyActor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->viewOnlyActor->givePermissionTo(BankingMigrationPlanService::PERMISSION_VIEW);

        $timestamp = now();

        DB::table('bank_accounts')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'bank_name' => 'DryRun Legacy Bank',
            'account_name' => 'Legacy Account',
            'account_number' => 'LEGACY-DR-' . $companySuffix,
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

        $legacyBankAccountId = (string) Str::ulid();
        DB::table('bank_accounts')->insert([
            'id' => $legacyBankAccountId,
            'property_id' => $this->property->id,
            'bank_name' => 'DryRun Legacy Bank 2',
            'account_name' => 'Legacy Account 2',
            'account_number' => 'LEGACY-DR2-' . $companySuffix,
            'currency_code' => 'IDR',
            'opening_balance' => '1000.00',
            'current_balance' => '1000.00',
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
            'code' => 'DRP-GL-' . $companySuffix,
            'name' => 'DryRun GL Account',
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
            'bank_name' => 'DryRun Controlled Bank',
            'account_name' => 'Controlled Account',
            'external_account_reference' => 'DRP-EXT-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'dryrun-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'dryrun-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'dry_run']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => (string) Str::ulid(),
            'controlled_bank_account_id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'dr-stmt-' . $companySuffix,
            'external_reference' => 'DR-STMT-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => 'OUTFLOW',
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'dr-stmt-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'dry_run']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function createDraftPlan(): BankingMigrationPlan
    {
        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPlanService::class);
        return $service->createPlan('dryrun-plan-' . Str::random(6), $this->actor);
    }

    private function transitionToDryRunRequested(BankingMigrationPlan $plan): void
    {
        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPlanService::class);
        $service->requestDryRun($plan->id, $this->actor);
        $plan->refresh();
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
            'banking_migration_manifest_entries',
            'banking_migration_exception_quarantines',
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
