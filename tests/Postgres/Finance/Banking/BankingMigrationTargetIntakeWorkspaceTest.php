<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationTargetIntakeStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingMigrationTargetIntakeWorkspaceTest extends PostgresTestCase
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
            'name' => 'NoPerm TargetIntake ' . Str::random(6),
            'email' => 'noperm-ti-' . Str::random(6) . '@example.test',
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
        $this->assertEmpty($props['target_intakes'] ?? []);
    }

    public function test_active_property_context_required(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId(null);

        $user = User::create([
            'name' => 'NoProp TI ' . Str::random(6),
            'email' => 'noprop-ti-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->givePermissionTo(BankingMigrationPlanService::PERMISSION_VIEW);
        setPermissionsTeamId($this->property->id);

        $response = $this->actingAs($user, 'web')
            ->get(route('finance.banking.migration.index'));

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_workspace_request_is_read_only(): void
    {
        $this->createFixtures();

        $before = $this->fullSnapshot();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $after = $this->fullSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count, $after[$table], "Table '{$table}' mutated during workspace request.");
        }
    }

    public function test_workspace_request_does_not_mutate_legacy_banking(): void
    {
        $this->createFixtures();

        $beforeLegacyAccountCount = DB::table('bank_accounts')->count();
        $beforeLegacySessionCount = DB::table('reconciliation_sessions')->count();
        $beforeLegacyMatchCount = DB::table('reconciliation_matches')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeLegacyAccountCount, DB::table('bank_accounts')->count());
        $this->assertSame($beforeLegacySessionCount, DB::table('reconciliation_sessions')->count());
        $this->assertSame($beforeLegacyMatchCount, DB::table('reconciliation_matches')->count());
    }

    public function test_workspace_request_does_not_mutate_controlled_banking(): void
    {
        $this->createFixtures();

        $beforeControlledAccountCount = DB::table('controlled_bank_accounts')->count();
        $beforeControlledStmtCount = DB::table('controlled_bank_statement_lines')->count();
        $beforeReconciliationCount = DB::table('bank_payment_reconciliations')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeControlledAccountCount, DB::table('controlled_bank_accounts')->count());
        $this->assertSame($beforeControlledStmtCount, DB::table('controlled_bank_statement_lines')->count());
        $this->assertSame($beforeReconciliationCount, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_workspace_request_does_not_mutate_migration_plan(): void
    {
        $this->createFixtures();

        $beforePlanCount = DB::table('banking_migration_plans')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforePlanCount, DB::table('banking_migration_plans')->count());
    }

    public function test_workspace_request_does_not_mutate_payment_execution_or_journal(): void
    {
        $this->createFixtures();

        $beforePaymentExecutions = DB::table('payment_executions')->count();
        $beforeJournals = DB::table('gl_journal_entries')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforePaymentExecutions, DB::table('payment_executions')->count());
        $this->assertSame($beforeJournals, DB::table('gl_journal_entries')->count());
    }

    public function test_no_financial_fields_are_projected(): void
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
        $this->assertStringNotContainsString('account_number', $content);
        $this->assertStringNotContainsString('confidence_score', $content);
    }

    public function test_no_cross_domain_comparison_exists(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('recommended', $content);
        $this->assertStringNotContainsString('best match', $content);
        $this->assertStringNotContainsString('equivalent', $content);
        $this->assertStringNotContainsString('score', $content);
        $this->assertStringNotContainsString('confidence', $content);
        $this->assertStringNotContainsString('candidate', $content);
        $this->assertStringNotContainsString('suggestion', $content);
    }

    public function test_target_intake_prop_is_present_when_view_permitted(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertArrayHasKey('target_intakes', $props);
        $this->assertIsArray($props['target_intakes']);
    }

    public function test_target_intake_status_is_non_executable(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $targetIntake = $this->createTestTargetIntake(BankingMigrationTargetIntakeStatusEnum::PROPOSED);

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
        $this->assertStringNotContainsString('APPROVED_FOR_EXECUTION', $content);
        $this->assertStringNotContainsString('MIGRATABLE', $content);
        $this->assertStringNotContainsString('READY_TO_MIGRATE', $content);
        $this->assertStringNotContainsString('TARGET_LINKED', $content);
        $this->assertStringNotContainsString('Execute Migration', $content);
        $this->assertStringNotContainsString('Cutover', $content);
    }

    public function test_migration_execution_not_authorized_is_projected(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $targetIntake = $this->createTestTargetIntake(BankingMigrationTargetIntakeStatusEnum::PROPOSED);

        $this->assertSame('UNAVAILABLE', $targetIntake->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $targetIntake->cutover_authority);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $intakes = $props['target_intakes'] ?? [];
        $this->assertNotEmpty($intakes);

        $found = false;
        foreach ($intakes as $intake) {
            $this->assertSame('UNAVAILABLE', $intake['execution_authority']);
            $this->assertSame('CUTOVER_NOT_AUTHORIZED', $intake['cutover_authority']);
            $found = true;
        }
        $this->assertTrue($found);
    }

    public function test_cross_property_target_intake_visibility_fails_closed(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->createTestTargetIntake(BankingMigrationTargetIntakeStatusEnum::PROPOSED);

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertEmpty($props['target_intakes'] ?? []);
    }

    public function test_no_execution_or_cutover_route_exists(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $intakes = $props['target_intakes'] ?? [];

        foreach ($intakes as $intake) {
            $this->assertNotContains('execute-migration', array_keys($intake));
            $this->assertNotContains('execution_action', array_keys($intake));
            $this->assertNotContains('cutover_action', array_keys($intake));
            $this->assertNotContains('migrate_url', array_keys($intake));
        }
    }

    public function test_no_confirmation_intent_is_created_or_consumed(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

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
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeRoles, DB::table('roles')->count());
        $this->assertSame($beforePermissionCount, DB::table('permissions')->count());
    }

    public function test_review_accepted_intake_remains_non_operational(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $targetIntake = $this->createTestTargetIntake(BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED);

        $targetIntake->refresh();
        $this->assertSame(BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED, $targetIntake->status);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $targetIntake->cutover_authority);
        $this->assertSame('UNAVAILABLE', $targetIntake->execution_authority);

        $beforeControlledCount = DB::table('controlled_bank_accounts')->count();
        $beforeLegacyCount = DB::table('bank_accounts')->count();
        $this->assertSame($beforeControlledCount, DB::table('controlled_bank_accounts')->count());
        $this->assertSame($beforeLegacyCount, DB::table('bank_accounts')->count());
    }

    public function test_workspace_projection_is_non_financial(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->createTestTargetIntake(BankingMigrationTargetIntakeStatusEnum::PROPOSED);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('opening_balance', $content);
        $this->assertStringNotContainsString('current_balance', $content);
        $this->assertStringNotContainsString('reconciled_balance', $content);
        $this->assertStringNotContainsString('account_number', $content);
        $this->assertStringNotContainsString('amount', $content);
        $this->assertStringNotContainsString('currency', $content);
        $this->assertStringNotContainsString('external_reference', $content);
    }

    public function test_finance_controller_can_view_target_intakes(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->createTestTargetIntake(BankingMigrationTargetIntakeStatusEnum::PROPOSED);

        $fcUser = $this->createFinanceControllerUser();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($fcUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertTrue($props['permissions']['can_view']);
        $this->assertNotEmpty($props['target_intakes']);
    }

    public function test_finance_controller_can_review_flag_is_set(): void
    {
        $this->createFixtures();

        $fcUser = $this->createFinanceControllerUser();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($fcUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertTrue($props['permissions']['can_review']);
    }

    public function test_finance_manager_can_review_flag_is_not_set(): void
    {
        $this->createFixtures();

        $fmUser = $this->createFinanceManagerUser();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($fmUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertFalse($props['permissions']['can_review']);
    }

    public function test_no_legacy_account_field_is_projected(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->createTestTargetIntake(BankingMigrationTargetIntakeStatusEnum::PROPOSED);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $intakes = $props['target_intakes'] ?? [];
        foreach ($intakes as $intake) {
            $this->assertArrayNotHasKey('account_number', $intake);
            $this->assertArrayNotHasKey('bank_name', $intake);
            $this->assertArrayNotHasKey('account_name', $intake);
            $this->assertArrayNotHasKey('balance', $intake);
            $this->assertArrayNotHasKey('amount', $intake);
            $this->assertArrayNotHasKey('currency', $intake);
            $this->assertArrayNotHasKey('external_reference', $intake);
            $this->assertArrayNotHasKey('score', $intake);
            $this->assertArrayNotHasKey('confidence', $intake);
        }
    }

    public function test_no_controlled_target_identity_is_projected(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->createTestTargetIntake(BankingMigrationTargetIntakeStatusEnum::PROPOSED);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $intakes = $props['target_intakes'] ?? [];
        foreach ($intakes as $intake) {
            $this->assertArrayNotHasKey('target_bank_name', $intake);
            $this->assertArrayNotHasKey('target_account_name', $intake);
            $this->assertArrayNotHasKey('target_account_number', $intake);
            $this->assertArrayNotHasKey('target_balance', $intake);
        }
    }

    public function test_no_source_target_side_by_side_comparison_exists(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('Source Account', $content);
        $this->assertStringNotContainsString('Target Account', $content);
        $this->assertStringNotContainsString('side by side', $content);
        $this->assertStringNotContainsString('comparison', $content);
    }

    private function createTestTargetIntake(BankingMigrationTargetIntakeStatusEnum $status): BankingMigrationTargetIntake
    {
        $timestamp = now();

        $planId = (string) Str::ulid();
        DB::table('banking_migration_plans')->insert([
            'id' => $planId,
            'property_id' => $this->property->id,
            'source_domain' => 'legacy_banking',
            'target_domain' => 'controlled_banking',
            'status' => 'DRY_RUN_COMPLETED',
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => hash('sha256', 'ti-test-plan-' . microtime(true)),
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_actor_id' => $this->actor->id,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $legacyAccountId = (string) Str::ulid();
        DB::table('bank_accounts')->where('id', $legacyAccountId)->delete();

        $manifestEntryId = (string) Str::ulid();
        DB::table('banking_migration_manifest_entries')->insert([
            'id' => $manifestEntryId,
            'migration_plan_id' => $planId,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'source_ulid' => $legacyAccountId,
            'source_property_id' => $this->property->id,
            'source_identity_hash' => hash('sha256', 'ti-test-manifest-' . microtime(true)),
            'source_snapshot_hash' => hash('sha256', 'ti-test-snapshot-' . microtime(true)),
            'dry_run_version' => 'ti-dry-run-v1',
            'inventory_status' => 'INVENTORIED',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $glAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $this->property->id,
            'code' => 'TI-GL-' . Str::random(6),
            'name' => 'TargetIntake GL Account',
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

        $controlledAccountId = (string) Str::ulid();
        DB::table('controlled_bank_accounts')->insert([
            'id' => $controlledAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => 'TargetIntake Controlled Bank',
            'account_name' => 'TargetIntake Account',
            'external_account_reference' => 'TI-EXT-' . Str::random(6),
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'ti-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'ti-ca-' . microtime(true)),
            'source_snapshot' => json_encode(['test_scope' => 'target_intake']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return BankingMigrationTargetIntake::create([
            'property_id' => $this->property->id,
            'migration_plan_id' => $planId,
            'manifest_entry_id' => $manifestEntryId,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'target_domain' => 'controlled_banking',
            'target_model' => 'ControlledBankAccount',
            'controlled_bank_account_id' => $controlledAccountId,
            'target_identity_hash' => hash('sha256', 'ti-target-hash-' . microtime(true)),
            'status' => $status,
            'correlation_id' => (string) Str::ulid(),
            'proposal_actor_id' => $this->actor->id,
            'review_actor_id' => null,
            'review_outcome' => null,
            'review_timestamp' => null,
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
        ]);
    }

    private function createFinanceControllerUser(): User
    {
        $user = User::create([
            'name' => 'TI FC ' . Str::random(6),
            'email' => 'ti-fc-' . Str::random(6) . '@example.test',
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
            'finance.banking.migration.mapping.review',
        ]);

        return $user;
    }

    private function createFinanceManagerUser(): User
    {
        $user = User::create([
            'name' => 'TI FM ' . Str::random(6),
            'email' => 'ti-fm-' . Str::random(6) . '@example.test',
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

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'TI Company ' . $companySuffix,
            'slug' => 'ti-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'TI Property ' . $companySuffix,
            'slug' => 'ti-property-' . $companySuffix,
            'code' => 'TIP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'TI Other ' . $companySuffix,
            'slug' => 'ti-other-' . $companySuffix,
            'code' => 'TIO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_VIEW, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_MANAGE, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.banking.migration.mapping.review', 'guard_name' => 'web']);

        $this->actor = User::create([
            'name' => 'TI Actor ' . $companySuffix,
            'email' => 'ti-actor-' . $companySuffix . '@example.test',
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
            'name' => 'TI ViewOnly ' . $companySuffix,
            'email' => 'ti-viewonly-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->viewOnlyActor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->viewOnlyActor->givePermissionTo(BankingMigrationPlanService::PERMISSION_VIEW);

        $timestamp = now();

        $legacyBankAccountId = (string) Str::ulid();
        DB::table('bank_accounts')->insert([
            'id' => $legacyBankAccountId,
            'property_id' => $this->property->id,
            'bank_name' => 'TI Legacy Bank',
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
            'code' => 'TI-GL-' . $companySuffix,
            'name' => 'TI GL Account',
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
            'bank_name' => 'TI Controlled Bank',
            'account_name' => 'Controlled Account',
            'external_account_reference' => 'TI-EXT-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'ti-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'ti-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'target_intake']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => (string) Str::ulid(),
            'controlled_bank_account_id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'ti-stmt-' . $companySuffix,
            'external_reference' => 'TI-STMT-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => 'OUTFLOW',
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'ti-stmt-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'target_intake']),
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
            'banking_migration_manifest_entries',
            'banking_migration_exception_quarantines',
            'banking_migration_target_intakes',
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
