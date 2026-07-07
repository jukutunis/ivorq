<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationPilotAuthorizationStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationPilotAuthorization;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingMigrationPilotAuthorizationWorkspaceTest extends PostgresTestCase
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

    public function test_active_property_context_required(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId(null);

        $user = User::create([
            'name' => 'PA NoProp ' . Str::random(6),
            'email' => 'pa-noprop-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->givePermissionTo(BankingMigrationPlanService::PERMISSION_VIEW);
        setPermissionsTeamId($this->property->id);

        $response = $this->actingAs($user, 'web')
            ->get(route('finance.banking.migration.index'));

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_view_permission_required_for_workspace(): void
    {
        $this->createFixtures();

        $user = User::create([
            'name' => 'NoPerm PA ' . Str::random(6),
            'email' => 'noperm-pa-' . Str::random(6) . '@example.test',
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
        $this->assertEmpty($props['pilot_authorizations'] ?? []);
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

    public function test_workspace_request_does_not_mutate_target_intake(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED);

        $beforeIntakeCount = DB::table('banking_migration_target_intakes')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeIntakeCount, DB::table('banking_migration_target_intakes')->count());
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

    public function test_workspace_request_does_not_mutate_financial_period_or_business_date(): void
    {
        $this->createFixtures();

        $beforeCashbook = DB::table('cashbook_transactions')->count();
        $beforeCashierSessions = DB::table('cashier_sessions')->count();
        $beforeCashInstruments = DB::table('cashier_payment_instruments')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeCashbook, DB::table('cashbook_transactions')->count());
        $this->assertSame($beforeCashierSessions, DB::table('cashier_sessions')->count());
        $this->assertSame($beforeCashInstruments, DB::table('cashier_payment_instruments')->count());
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
        $this->assertStringNotContainsString('amount', $content);
        $this->assertStringNotContainsString('currency', $content);
    }

    public function test_no_source_target_identity_is_projected(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $pilotAuths = $props['pilot_authorizations'] ?? [];
        foreach ($pilotAuths as $auth) {
            $this->assertArrayNotHasKey('source_account_number', $auth);
            $this->assertArrayNotHasKey('source_bank_name', $auth);
            $this->assertArrayNotHasKey('source_account_name', $auth);
            $this->assertArrayNotHasKey('target_account_number', $auth);
            $this->assertArrayNotHasKey('target_bank_name', $auth);
            $this->assertArrayNotHasKey('target_account_name', $auth);
            $this->assertArrayNotHasKey('balance', $auth);
            $this->assertArrayNotHasKey('amount', $auth);
            $this->assertArrayNotHasKey('currency', $auth);
            $this->assertArrayNotHasKey('external_reference', $auth);
            $this->assertArrayNotHasKey('score', $auth);
            $this->assertArrayNotHasKey('confidence', $auth);
        }
    }

    public function test_no_comparison_fields_are_projected(): void
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
        $this->assertStringNotContainsString('comparison', $content);
        $this->assertStringNotContainsString('recommended', $content);
        $this->assertStringNotContainsString('best match', $content);
        $this->assertStringNotContainsString('equivalent', $content);
        $this->assertStringNotContainsString('score', $content);
        $this->assertStringNotContainsString('confidence', $content);
        $this->assertStringNotContainsString('candidate', $content);
        $this->assertStringNotContainsString('suggestion', $content);
    }

    public function test_pilot_authorization_status_is_non_executable(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED);

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
        $this->assertStringNotContainsString('EXECUTION_AUTHORIZED', $content);
        $this->assertStringNotContainsString('READY_TO_MIGRATE', $content);
        $this->assertStringNotContainsString('PILOT_READY', $content);
        $this->assertStringNotContainsString('Execute Migration', $content);
        $this->assertStringNotContainsString('Cutover', $content);
        $this->assertStringNotContainsString('Rollback', $content);
    }

    public function test_migration_execution_not_implemented_is_projected(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $pilotAuth = $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED);

        $this->assertSame('MIGRATION_EXECUTION_NOT_IMPLEMENTED', $pilotAuth->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $pilotAuth->cutover_authority);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $pilotAuths = $props['pilot_authorizations'] ?? [];
        $this->assertNotEmpty($pilotAuths);

        foreach ($pilotAuths as $auth) {
            $this->assertSame('MIGRATION_EXECUTION_NOT_IMPLEMENTED', $auth['execution_authority']);
            $this->assertSame('CUTOVER_NOT_AUTHORIZED', $auth['cutover_authority']);
        }
    }

    public function test_review_accepted_pilot_auth_remains_non_operational(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $pilotAuth = $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REVIEW_ACCEPTED);

        $pilotAuth->refresh();
        $this->assertSame(BankingMigrationPilotAuthorizationStatusEnum::REVIEW_ACCEPTED, $pilotAuth->status);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $pilotAuth->cutover_authority);
        $this->assertSame('MIGRATION_EXECUTION_NOT_IMPLEMENTED', $pilotAuth->execution_authority);

        $beforeControlledCount = DB::table('controlled_bank_accounts')->count();
        $beforeLegacyCount = DB::table('bank_accounts')->count();
        $this->assertSame($beforeControlledCount, DB::table('controlled_bank_accounts')->count());
        $this->assertSame($beforeLegacyCount, DB::table('bank_accounts')->count());
    }

    public function test_cross_property_pilot_authorization_visibility_fails_closed(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED);

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertEmpty($props['pilot_authorizations'] ?? []);
    }

    public function test_no_execution_or_cutover_route_exists(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $pilotAuths = $props['pilot_authorizations'] ?? [];

        foreach ($pilotAuths as $auth) {
            $this->assertNotContains('execute-migration', array_keys($auth));
            $this->assertNotContains('execution_action', array_keys($auth));
            $this->assertNotContains('cutover_action', array_keys($auth));
            $this->assertNotContains('migrate_url', array_keys($auth));
            $this->assertNotContains('rollback_action', array_keys($auth));
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

    public function test_pilot_authorization_prop_is_present_when_view_permitted(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertArrayHasKey('pilot_authorizations', $props);
        $this->assertIsArray($props['pilot_authorizations']);
    }

    public function test_no_request_or_review_buttons_in_wave1(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('Request Pilot Authorization', $content);
        $this->assertStringNotContainsString('Review Pilot Authorization', $content);
    }

    public function test_finance_controller_can_review_pilot_auth_flag_is_set(): void
    {
        $this->createFixtures();

        $fcUser = $this->createFinanceControllerUser();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($fcUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertTrue($props['permissions']['can_review_pilot_auth']);
    }

    public function test_finance_manager_cannot_review_pilot_auth(): void
    {
        $this->createFixtures();

        $fmUser = $this->createFinanceManagerUser();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($fmUser, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertFalse($props['permissions']['can_review_pilot_auth']);
    }

    public function test_authorization_scope_is_account_identity_pilot_only(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $pilotAuth = $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED);

        $this->assertSame('account_identity_pilot_only', $pilotAuth->authorization_scope);
    }

    public function test_no_legacy_controlled_operational_banking_tables_mutated_during_workspace(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED);

        $beforeLegacy = DB::table('bank_accounts')->count();
        $beforeControlled = DB::table('controlled_bank_accounts')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeLegacy, DB::table('bank_accounts')->count());
        $this->assertSame($beforeControlled, DB::table('controlled_bank_accounts')->count());
    }

    private function createTestPilotAuthorization(BankingMigrationPilotAuthorizationStatusEnum $status): BankingMigrationPilotAuthorization
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
            'idempotency_key' => hash('sha256', 'pa-test-plan-' . microtime(true)),
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
            'source_identity_hash' => hash('sha256', 'pa-test-manifest-' . microtime(true)),
            'source_snapshot_hash' => hash('sha256', 'pa-test-snapshot-' . microtime(true)),
            'dry_run_version' => 'pa-dry-run-v1',
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
            'code' => 'PA-GL-' . Str::random(6),
            'name' => 'PilotAuth GL Account',
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
            'bank_name' => 'PilotAuth Controlled Bank',
            'account_name' => 'PilotAuth Account',
            'external_account_reference' => 'PA-EXT-' . Str::random(6),
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'pa-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'pa-ca-' . microtime(true)),
            'source_snapshot' => json_encode(['test_scope' => 'pilot_auth']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $targetIntakeId = (string) Str::ulid();
        DB::table('banking_migration_target_intakes')->insert([
            'id' => $targetIntakeId,
            'property_id' => $this->property->id,
            'migration_plan_id' => $planId,
            'manifest_entry_id' => $manifestEntryId,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'target_domain' => 'controlled_banking',
            'target_model' => 'ControlledBankAccount',
            'controlled_bank_account_id' => $controlledAccountId,
            'target_identity_hash' => hash('sha256', 'pa-target-hash-' . microtime(true)),
            'status' => 'REVIEW_ACCEPTED',
            'correlation_id' => (string) Str::ulid(),
            'proposal_actor_id' => $this->actor->id,
            'review_actor_id' => null,
            'review_outcome' => 'REVIEW_ACCEPTED',
            'review_timestamp' => $timestamp,
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return BankingMigrationPilotAuthorization::create([
            'property_id' => $this->property->id,
            'migration_plan_id' => $planId,
            'manifest_entry_id' => $manifestEntryId,
            'target_intake_id' => $targetIntakeId,
            'authorization_scope' => 'account_identity_pilot_only',
            'status' => $status,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => hash('sha256', 'pa-ik-' . microtime(true)),
            'request_actor_id' => $this->actor->id,
            'review_actor_id' => null,
            'review_outcome' => null,
            'review_timestamp' => null,
            'execution_authority' => 'MIGRATION_EXECUTION_NOT_IMPLEMENTED',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
        ]);
    }

    private function createFinanceControllerUser(): User
    {
        $user = User::create([
            'name' => 'PA FC ' . Str::random(6),
            'email' => 'pa-fc-' . Str::random(6) . '@example.test',
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
            'finance.banking.migration.pilot.authorization.review',
        ]);

        return $user;
    }

    private function createFinanceManagerUser(): User
    {
        $user = User::create([
            'name' => 'PA FM ' . Str::random(6),
            'email' => 'pa-fm-' . Str::random(6) . '@example.test',
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
            'name' => 'PA Company ' . $companySuffix,
            'slug' => 'pa-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'PA Property ' . $companySuffix,
            'slug' => 'pa-property-' . $companySuffix,
            'code' => 'PAP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'PA Other ' . $companySuffix,
            'slug' => 'pa-other-' . $companySuffix,
            'code' => 'PAO' . $companySuffix,
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
            'name' => 'PA Actor ' . $companySuffix,
            'email' => 'pa-actor-' . $companySuffix . '@example.test',
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
            'name' => 'PA ViewOnly ' . $companySuffix,
            'email' => 'pa-viewonly-' . $companySuffix . '@example.test',
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
            'bank_name' => 'PA Legacy Bank',
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
            'code' => 'PA-GL-' . $companySuffix,
            'name' => 'PA GL Account',
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
            'bank_name' => 'PA Controlled Bank',
            'account_name' => 'Controlled Account',
            'external_account_reference' => 'PA-EXT-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'pa-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'pa-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'pilot_auth']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => (string) Str::ulid(),
            'controlled_bank_account_id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'pa-stmt-' . $companySuffix,
            'external_reference' => 'PA-STMT-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => 'OUTFLOW',
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'pa-stmt-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'pilot_auth']),
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
            'banking_migration_pilot_authorizations',
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
