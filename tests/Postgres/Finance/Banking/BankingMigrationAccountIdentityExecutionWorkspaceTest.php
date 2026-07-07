<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingMigrationAccountIdentityExecutionWorkspaceTest extends PostgresTestCase
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
            'name' => 'EXEC NoProp ' . Str::random(6),
            'email' => 'exec-noprop-' . Str::random(6) . '@example.test',
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
            'name' => 'EXEC NoView ' . Str::random(6),
            'email' => 'exec-noview-' . Str::random(6) . '@example.test',
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
        $this->assertEmpty($props['execution_ledger']['records'] ?? []);
    }

    public function test_cross_property_execution_evidence_fails_closed(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createReviewAcceptedPilotAuthorization();

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $ledger = $props['execution_ledger'] ?? [];
        $records = $ledger['records'] ?? [];
        $this->assertEmpty($records);
    }

    public function test_workspace_request_is_read_only(): void
    {
        $this->createFixtures();

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

    public function test_workspace_does_not_mutate_legacy_banking(): void
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

    public function test_workspace_does_not_mutate_controlled_banking(): void
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

    public function test_workspace_does_not_mutate_migration_plan_or_manifest(): void
    {
        $this->createFixtures();

        $beforePlanCount = DB::table('banking_migration_plans')->count();
        $beforeManifestCount = DB::table('banking_migration_manifest_entries')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforePlanCount, DB::table('banking_migration_plans')->count());
        $this->assertSame($beforeManifestCount, DB::table('banking_migration_manifest_entries')->count());
    }

    public function test_workspace_does_not_mutate_quarantine_target_intake_or_pilot_auth(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createReviewAcceptedPilotAuthorization();

        $beforeQuarantine = DB::table('banking_migration_exception_quarantines')->count();
        $beforeIntake = DB::table('banking_migration_target_intakes')->count();
        $beforePilotAuth = DB::table('banking_migration_pilot_authorizations')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeQuarantine, DB::table('banking_migration_exception_quarantines')->count());
        $this->assertSame($beforeIntake, DB::table('banking_migration_target_intakes')->count());
        $this->assertSame($beforePilotAuth, DB::table('banking_migration_pilot_authorizations')->count());
    }

    public function test_workspace_does_not_mutate_execution_ledger(): void
    {
        $this->createFixtures();

        $beforeExecLedger = DB::table('banking_migration_account_identity_executions')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeExecLedger, DB::table('banking_migration_account_identity_executions')->count());
    }

    public function test_workspace_does_not_mutate_payment_journal_or_financial(): void
    {
        $this->createFixtures();

        $beforePayments = DB::table('payment_executions')->count();
        $beforeJournals = DB::table('gl_journal_entries')->count();
        $beforeCashbook = DB::table('cashbook_transactions')->count();
        $beforeCashierSessions = DB::table('cashier_sessions')->count();
        $beforeCashInstruments = DB::table('cashier_payment_instruments')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforePayments, DB::table('payment_executions')->count());
        $this->assertSame($beforeJournals, DB::table('gl_journal_entries')->count());
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

    public function test_no_source_target_identity_details_in_execution_ledger(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $ledger = $props['execution_ledger'] ?? [];
        $records = $ledger['records'] ?? [];

        foreach ($records as $record) {
            $this->assertArrayNotHasKey('source_account_number', $record);
            $this->assertArrayNotHasKey('source_bank_name', $record);
            $this->assertArrayNotHasKey('source_account_name', $record);
            $this->assertArrayNotHasKey('target_account_number', $record);
            $this->assertArrayNotHasKey('target_bank_name', $record);
            $this->assertArrayNotHasKey('target_account_name', $record);
            $this->assertArrayNotHasKey('balance', $record);
            $this->assertArrayNotHasKey('amount', $record);
            $this->assertArrayNotHasKey('currency', $record);
            $this->assertArrayNotHasKey('external_reference', $record);
            $this->assertArrayNotHasKey('score', $record);
            $this->assertArrayNotHasKey('confidence', $record);
        }
    }

    public function test_no_execution_action_route_exists_in_wave1(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('Execute Account Identity Pilot', $content);
        $this->assertStringNotContainsString('Execute Migration', $content);
        $this->assertStringNotContainsString('Start Execution', $content);
        $this->assertStringNotContainsString('migration-execute', strtolower($content));
    }

    public function test_no_confirmation_intent_issued_or_consumed(): void
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

    public function test_execution_permission_assignment_is_limited(): void
    {
        $this->createFixtures();

        $executePermission = Permission::firstOrCreate([
            'name' => 'finance.banking.migration.pilot.execution.execute',
            'guard_name' => 'web',
        ]);

        $fmUser = User::create([
            'name' => 'EXEC FM ' . Str::random(6),
            'email' => 'exec-fm-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $fmUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $fmUser->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            BankingMigrationPlanService::PERMISSION_MANAGE,
            'finance.banking.migration.pilot.execution.execute',
        ]);

        $fcUser = User::create([
            'name' => 'EXEC FC ' . Str::random(6),
            'email' => 'exec-fc-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $fcUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $fcUser->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            'finance.banking.migration.mapping.review',
            'finance.banking.migration.pilot.authorization.review',
        ]);

        setPermissionsTeamId($this->property->id);

        $this->assertTrue($fmUser->can('finance.banking.migration.pilot.execution.execute'));
        $this->assertFalse($fcUser->can('finance.banking.migration.pilot.execution.execute'));
    }

    public function test_no_unrelated_role_or_permission_changed(): void
    {
        $this->createFixtures();

        $beforeRoles = DB::table('roles')->count();
        $beforePermissions = DB::table('permissions')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $this->assertSame($beforeRoles, DB::table('roles')->count());
        $this->assertSame($beforePermissions, DB::table('permissions')->count());
    }

    public function test_execution_ledger_schema_exists_but_has_zero_records_initially(): void
    {
        $this->createFixtures();

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('banking_migration_account_identity_executions'));

        $executionCount = DB::table('banking_migration_account_identity_executions')->count();
        $this->assertSame(0, $executionCount, 'Execution ledger table should have zero records initially.');

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $executionCountAfter = DB::table('banking_migration_account_identity_executions')->count();
        $this->assertSame(0, $executionCountAfter);

        $props = $response->inertiaProps();
        $ledger = $props['execution_ledger'] ?? [];
        $records = $ledger['records'] ?? [];
        $this->assertIsArray($records);
        $this->assertEmpty($records);
    }

    public function test_execution_ledger_projection_is_present(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertArrayHasKey('execution_ledger', $props);
        $this->assertIsArray($props['execution_ledger']);
        $this->assertArrayHasKey('records', $props['execution_ledger']);
        $this->assertArrayHasKey('summary', $props['execution_ledger']);
        $this->assertSame(0, $props['execution_ledger']['summary']['total_executions']);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $props['execution_ledger']['summary']['cutover_authority']);
    }

    public function test_execution_preconditions_reflect_wave1_state(): void
    {
        $this->createFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $preconditions = $props['execution_preconditions'] ?? [];

        foreach ($preconditions as $pc) {
            $this->assertSame('PERMISSION_REGISTERED', $pc['future_execution_permission']);
            $this->assertSame('NOT_AUTHORIZED', $pc['future_cutover_permission']);
            $this->assertSame('AVAILABLE', $pc['execution_ledger_schema']);
        }
    }

    public function test_constants_are_projected(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $constants = $props['constants'] ?? [];

        $this->assertArrayHasKey('execution_ledger_available', $constants);
        $this->assertTrue($constants['execution_ledger_available']);
        $this->assertArrayHasKey('execution_not_yet_activated', $constants);
        $this->assertSame('ACCOUNT_IDENTITY_LINEAGE_EXECUTION_NOT_YET_ACTIVATED', $constants['execution_not_yet_activated']);
    }

    public function test_no_comparison_or_inference_fields_rendered(): void
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
        $this->assertStringNotContainsString('Execute', $content);
        $this->assertStringNotContainsString('Cutover', $content);
        $this->assertStringNotContainsString('Rollback', $content);
    }

    public function test_no_property_cutover_in_execution_ledger(): void
    {
        $this->createFixtures();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.migration.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $ledger = $props['execution_ledger'] ?? [];
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $ledger['summary']['cutover_authority']);

        $content = $response->getContent();
        $this->assertStringNotContainsString('CUTOVER_READY', $content);
        $this->assertStringNotContainsString('CUTOVER_COMPLETE', $content);
    }

    private function createReviewAcceptedPilotAuthorization(): void
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
            'idempotency_key' => hash('sha256', 'exec-plan-' . microtime(true)),
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_actor_id' => $this->actor->id,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $legacyAccountId = (string) Str::ulid();
        $manifestEntryId = (string) Str::ulid();
        DB::table('banking_migration_manifest_entries')->insert([
            'id' => $manifestEntryId,
            'migration_plan_id' => $planId,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'source_ulid' => $legacyAccountId,
            'source_property_id' => $this->property->id,
            'source_identity_hash' => hash('sha256', 'exec-manifest-' . microtime(true)),
            'source_snapshot_hash' => hash('sha256', 'exec-snapshot-' . microtime(true)),
            'dry_run_version' => 'exec-dry-run-v1',
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
            'code' => 'EXEC-GL-' . Str::random(6),
            'name' => 'Exec GL Account',
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
            'bank_name' => 'Exec Controlled Bank',
            'account_name' => 'Exec Account',
            'external_account_reference' => 'EXEC-EXT-' . Str::random(6),
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'exec-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'exec-ca-' . microtime(true)),
            'source_snapshot' => json_encode(['test_scope' => 'exec_test']),
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
            'target_identity_hash' => hash('sha256', 'exec-target-' . microtime(true)),
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

        DB::table('banking_migration_pilot_authorizations')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'migration_plan_id' => $planId,
            'manifest_entry_id' => $manifestEntryId,
            'target_intake_id' => $targetIntakeId,
            'authorization_scope' => 'account_identity_pilot_only',
            'status' => 'REVIEW_ACCEPTED',
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => hash('sha256', 'exec-ik-' . microtime(true)),
            'request_actor_id' => $this->actor->id,
            'review_actor_id' => null,
            'review_outcome' => 'REVIEW_ACCEPTED',
            'review_timestamp' => $timestamp,
            'execution_authority' => 'MIGRATION_EXECUTION_NOT_IMPLEMENTED',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
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
            'banking_migration_account_identity_executions',
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

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'EXEC Company ' . $companySuffix,
            'slug' => 'exec-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'EXEC Property ' . $companySuffix,
            'slug' => 'exec-property-' . $companySuffix,
            'code' => 'EXP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'EXEC Other ' . $companySuffix,
            'slug' => 'exec-other-' . $companySuffix,
            'code' => 'EXO' . $companySuffix,
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
        Permission::firstOrCreate(['name' => 'finance.banking.migration.pilot.execution.execute', 'guard_name' => 'web']);

        $this->actor = User::create([
            'name' => 'EXEC Actor ' . $companySuffix,
            'email' => 'exec-actor-' . $companySuffix . '@example.test',
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
            'name' => 'EXEC ViewOnly ' . $companySuffix,
            'email' => 'exec-viewonly-' . $companySuffix . '@example.test',
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
            'bank_name' => 'EXEC Legacy Bank',
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
            'code' => 'EXEC-GL-' . $companySuffix,
            'name' => 'EXEC GL Account',
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
            'bank_name' => 'EXEC Controlled Bank',
            'account_name' => 'Controlled Account',
            'external_account_reference' => 'EXEC-EXT-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'exec-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'exec-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'exec_test']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => (string) Str::ulid(),
            'controlled_bank_account_id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'exec-stmt-' . $companySuffix,
            'external_reference' => 'EXEC-STMT-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => 'OUTFLOW',
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'exec-stmt-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'exec_test']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
