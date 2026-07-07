<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationPilotAuthorizationStatusEnum;
use Modules\Finance\Banking\Enums\BankingMigrationTargetIntakeStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationPilotAuthorization;
use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Finance\Banking\Services\BankingMigrationPilotAuthorizationService;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingMigrationPilotAuthorizationActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $financeManager;
    private User $financeController;
    private User $otherFinanceController;
    private User $glAccountant;
    private User $apOfficer;
    private User $noPermUser;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();

        $this->post(route('finance.banking.migration.pilot-authorization.request'), [
            'banking_migration_target_intake_id' => $intake->id,
        ])->assertRedirect();
    }

    public function test_unauthenticated_review_is_denied(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        $this->post(route('finance.banking.migration.pilot-authorization.review', [
            'pilotAuth' => $pilotAuth->id,
        ]), [
            'review_outcome' => 'REVIEW_ACCEPTED',
        ])->assertRedirect();
    }

    public function test_finance_manager_can_request_for_review_accepted_intake(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $response->assertSessionHasNoErrors();

        $pilotAuth = BankingMigrationPilotAuthorization::where('target_intake_id', $intake->id)->first();
        $this->assertNotNull($pilotAuth);
        $this->assertSame(BankingMigrationPilotAuthorizationStatusEnum::REQUESTED, $pilotAuth->status);
        $this->assertSame('MIGRATION_EXECUTION_NOT_IMPLEMENTED', $pilotAuth->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $pilotAuth->cutover_authority);
    }

    public function test_finance_controller_cannot_request(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeController, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertForbidden();
    }

    public function test_finance_controller_can_independently_review(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeController, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_ACCEPTED',
            ])
            ->assertRedirect();

        $response->assertSessionHasNoErrors();

        $pilotAuth->refresh();
        $this->assertSame(BankingMigrationPilotAuthorizationStatusEnum::REVIEW_ACCEPTED, $pilotAuth->status);
        $this->assertSame($this->financeController->id, $pilotAuth->review_actor_id);
        $this->assertSame('REVIEW_ACCEPTED', $pilotAuth->review_outcome);
        $this->assertNotNull($pilotAuth->review_timestamp);
        $this->assertSame('MIGRATION_EXECUTION_NOT_IMPLEMENTED', $pilotAuth->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $pilotAuth->cutover_authority);
    }

    public function test_finance_manager_cannot_review(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_ACCEPTED',
            ])
            ->assertForbidden();
    }

    public function test_general_ledger_accountant_cannot_request_or_review(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->glAccountant, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertForbidden();

        $this->withSession($this->propertySession())
            ->actingAs($this->glAccountant, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_ACCEPTED',
            ])
            ->assertForbidden();
    }

    public function test_accounts_payable_officer_cannot_request_or_review(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->apOfficer, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertForbidden();

        $this->withSession($this->propertySession())
            ->actingAs($this->apOfficer, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_ACCEPTED',
            ])
            ->assertForbidden();
    }

    public function test_cross_property_intake_fails_closed(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();

        setPermissionsTeamId($this->otherProperty->id);

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $this->assertNull(BankingMigrationPilotAuthorization::where('target_intake_id', $intake->id)->first());
    }

    public function test_non_review_accepted_intake_fails_closed(): void
    {
        $this->createFixtures();

        $intake = $this->createTargetIntake(BankingMigrationTargetIntakeStatusEnum::PROPOSED);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $this->assertNull(BankingMigrationPilotAuthorization::where('target_intake_id', $intake->id)->first());
    }

    public function test_non_bankaccount_manifest_entry_fails_closed(): void
    {
        $this->createFixtures();

        $timestamp = now();
        $planId = (string) Str::ulid();
        DB::table('banking_migration_plans')->insert([
            'id' => $planId,
            'property_id' => $this->property->id,
            'source_domain' => 'legacy_banking',
            'target_domain' => 'controlled_banking',
            'status' => 'DRY_RUN_COMPLETED',
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => hash('sha256', 'pa-test-nonba-' . microtime(true)),
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_actor_id' => $this->financeManager->id,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $manifestEntryId = (string) Str::ulid();
        DB::table('banking_migration_manifest_entries')->insert([
            'id' => $manifestEntryId,
            'migration_plan_id' => $planId,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankStatementLine',
            'source_ulid' => (string) Str::ulid(),
            'source_property_id' => $this->property->id,
            'source_identity_hash' => hash('sha256', 'pa-nonba-' . microtime(true)),
            'source_snapshot_hash' => hash('sha256', 'pa-nonba-snap-' . microtime(true)),
            'dry_run_version' => 'pa-dry-run-v1',
            'inventory_status' => 'INVENTORIED',
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $glAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $this->property->id,
            'code' => 'PA-NBA-' . Str::random(6),
            'name' => 'PilotAuth NonBA GL',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $controlledAccountId = (string) Str::ulid();
        DB::table('controlled_bank_accounts')->insert([
            'id' => $controlledAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => 'PilotAuth NonBA Ctrl Bank',
            'account_name' => 'PilotAuth NonBA',
            'external_account_reference' => 'PA-NBA-' . Str::random(6),
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'pa-nonba-test',
            'registered_by' => $this->financeManager->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'pa-nonba-ca-' . microtime(true)),
            'source_snapshot' => json_encode(['test_scope' => 'pilot_auth_non_BA']),
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
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
            'source_model' => 'BankStatementLine',
            'target_domain' => 'controlled_banking',
            'target_model' => 'ControlledBankAccount',
            'controlled_bank_account_id' => $controlledAccountId,
            'target_identity_hash' => hash('sha256', 'pa-nonba-th-' . microtime(true)),
            'status' => 'REVIEW_ACCEPTED',
            'correlation_id' => (string) Str::ulid(),
            'proposal_actor_id' => $this->financeManager->id,
            'review_actor_id' => null,
            'review_outcome' => 'REVIEW_ACCEPTED',
            'review_timestamp' => $timestamp,
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $targetIntakeId,
            ])
            ->assertRedirect();

        $this->assertNull(BankingMigrationPilotAuthorization::where('target_intake_id', $targetIntakeId)->first());
    }

    public function test_inactive_controlled_target_fails_closed(): void
    {
        $this->createFixtures();

        $timestamp = now();
        $planId = (string) Str::ulid();
        DB::table('banking_migration_plans')->insert([
            'id' => $planId,
            'property_id' => $this->property->id,
            'source_domain' => 'legacy_banking',
            'target_domain' => 'controlled_banking',
            'status' => 'DRY_RUN_COMPLETED',
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => hash('sha256', 'pa-inactive-' . microtime(true)),
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_actor_id' => $this->financeManager->id,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $manifestEntryId = (string) Str::ulid();
        DB::table('banking_migration_manifest_entries')->insert([
            'id' => $manifestEntryId,
            'migration_plan_id' => $planId,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'source_ulid' => (string) Str::ulid(),
            'source_property_id' => $this->property->id,
            'source_identity_hash' => hash('sha256', 'pa-inactive-me-' . microtime(true)),
            'source_snapshot_hash' => hash('sha256', 'pa-inactive-snap-' . microtime(true)),
            'dry_run_version' => 'pa-dry-run-v1',
            'inventory_status' => 'INVENTORIED',
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $glAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $this->property->id,
            'code' => 'PA-INACT-' . Str::random(6),
            'name' => 'PilotAuth Inactive GL',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $controlledAccountId = (string) Str::ulid();
        DB::table('controlled_bank_accounts')->insert([
            'id' => $controlledAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => 'PilotAuth Inactive Ctrl',
            'account_name' => 'PilotAuth Inactive',
            'external_account_reference' => 'PA-INACT-' . Str::random(6),
            'currency_code' => 'IDR',
            'is_active' => false,
            'source_reference' => 'pa-inactive-test',
            'registered_by' => $this->financeManager->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'pa-inact-ca-' . microtime(true)),
            'source_snapshot' => json_encode(['test_scope' => 'pilot_auth_inactive']),
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
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
            'target_identity_hash' => hash('sha256', 'pa-inact-th-' . microtime(true)),
            'status' => 'REVIEW_ACCEPTED',
            'correlation_id' => (string) Str::ulid(),
            'proposal_actor_id' => $this->financeManager->id,
            'review_actor_id' => null,
            'review_outcome' => 'REVIEW_ACCEPTED',
            'review_timestamp' => $timestamp,
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $targetIntakeId,
            ])
            ->assertRedirect();

        $this->assertNull(BankingMigrationPilotAuthorization::where('target_intake_id', $targetIntakeId)->first());
    }

    public function test_request_writes_only_pilot_authorization_table(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();

        $before = $this->fullSnapshot();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $pilotAuth = BankingMigrationPilotAuthorization::where('target_intake_id', $intake->id)->first();
        $this->assertNotNull($pilotAuth);

        $after = $this->fullSnapshot();

        foreach ($before as $table => $count) {
            if ($table === 'banking_migration_pilot_authorizations') {
                $this->assertSame($count + 1, $after[$table], "Table '{$table}' should have +1 entry.");
            } else {
                $this->assertSame($count, $after[$table], "Table '{$table}' mutated during request.");
            }
        }
    }

    public function test_review_writes_only_pilot_authorization_table(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        $before = $this->fullSnapshot();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeController, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_ACCEPTED',
            ])
            ->assertRedirect();

        $after = $this->fullSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count, $after[$table], "Table '{$table}' mutated during review.");
        }
    }

    public function test_request_does_not_mutate_migration_plan(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $beforePlans = DB::table('banking_migration_plans')->count();
        $beforeEntries = DB::table('banking_migration_manifest_entries')->count();
        $beforeIntakes = DB::table('banking_migration_target_intakes')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $this->assertSame($beforePlans, DB::table('banking_migration_plans')->count());
        $this->assertSame($beforeEntries, DB::table('banking_migration_manifest_entries')->count());
        $this->assertSame($beforeIntakes, DB::table('banking_migration_target_intakes')->count());
    }

    public function test_no_legacy_operational_record_changes_during_request(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $beforeLegacy = DB::table('bank_accounts')->count();
        $beforeSessions = DB::table('reconciliation_sessions')->count();
        $beforeMatches = DB::table('reconciliation_matches')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $this->assertSame($beforeLegacy, DB::table('bank_accounts')->count());
        $this->assertSame($beforeSessions, DB::table('reconciliation_sessions')->count());
        $this->assertSame($beforeMatches, DB::table('reconciliation_matches')->count());
    }

    public function test_no_controlled_operational_record_changes_during_request(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $beforeControlled = DB::table('controlled_bank_accounts')->count();
        $beforeStmts = DB::table('controlled_bank_statement_lines')->count();
        $beforeRecon = DB::table('bank_payment_reconciliations')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $this->assertSame($beforeControlled, DB::table('controlled_bank_accounts')->count());
        $this->assertSame($beforeStmts, DB::table('controlled_bank_statement_lines')->count());
        $this->assertSame($beforeRecon, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_no_operational_services_mutated_during_request(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $beforePayments = DB::table('payment_executions')->count();
        $beforeJournals = DB::table('gl_journal_entries')->count();
        $beforeCashbook = DB::table('cashbook_transactions')->count();
        $beforeCashierSessions = DB::table('cashier_sessions')->count();
        $beforeCashInstruments = DB::table('cashier_payment_instruments')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $this->assertSame($beforePayments, DB::table('payment_executions')->count());
        $this->assertSame($beforeJournals, DB::table('gl_journal_entries')->count());
        $this->assertSame($beforeCashbook, DB::table('cashbook_transactions')->count());
        $this->assertSame($beforeCashierSessions, DB::table('cashier_sessions')->count());
        $this->assertSame($beforeCashInstruments, DB::table('cashier_payment_instruments')->count());
    }

    public function test_same_target_intake_cannot_create_duplicate_active_request(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $first = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $count = BankingMigrationPilotAuthorization::where('target_intake_id', $intake->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_request_actor_cannot_review_own_request(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_ACCEPTED',
            ])
            ->assertForbidden();
    }

    public function test_review_replay_cannot_alter_completed_status(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeController, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_ACCEPTED',
            ])
            ->assertRedirect();

        $this->withSession($this->propertySession())
            ->actingAs($this->financeController, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_REJECTED',
            ])
            ->assertRedirect();

        $pilotAuth->refresh();
        $this->assertSame(BankingMigrationPilotAuthorizationStatusEnum::REVIEW_ACCEPTED, $pilotAuth->status);
    }

    public function test_review_acceptance_preserves_non_operational_markers(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeController, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_ACCEPTED',
            ])
            ->assertRedirect();

        $pilotAuth->refresh();
        $this->assertSame('MIGRATION_EXECUTION_NOT_IMPLEMENTED', $pilotAuth->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $pilotAuth->cutover_authority);
    }

    public function test_review_rejection_preserves_non_operational_markers(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();
        $pilotAuth = $this->requestPilotAuthorizationAs($this->financeManager, $intake);

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeController, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.review', [
                'pilotAuth' => $pilotAuth->id,
            ]), [
                'review_outcome' => 'REVIEW_REJECTED',
            ])
            ->assertRedirect();

        $pilotAuth->refresh();
        $this->assertSame('MIGRATION_EXECUTION_NOT_IMPLEMENTED', $pilotAuth->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $pilotAuth->cutover_authority);
    }

    public function test_no_execution_or_cutover_route_exists(): void
    {
        $this->createFixtures();

        $this->assertFalse(collect(app('router')->getRoutes()->getRoutesByName())->has('finance.banking.migration.execute'));
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutesByName())->has('finance.banking.migration.cutover'));
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutesByName())->has('finance.banking.migration.rollback'));
    }

    public function test_no_confirmation_intent_is_created_or_consumed(): void
    {
        $this->createFixtures();

        $intake = $this->createReviewAcceptedTargetIntake();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.request'), [
                'banking_migration_target_intake_id' => $intake->id,
            ])
            ->assertRedirect();

        $content = $response->getContent();
        $this->assertStringNotContainsString('sensitive-action-confirmation', $content);
    }

    private function createReviewAcceptedTargetIntake(): BankingMigrationTargetIntake
    {
        return $this->createTargetIntake(BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED);
    }

    private function createTargetIntake(BankingMigrationTargetIntakeStatusEnum $status): BankingMigrationTargetIntake
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
            'idempotency_key' => hash('sha256', 'pa-intake-plan-' . microtime(true)),
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_actor_id' => $this->financeManager->id,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
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
            'source_identity_hash' => hash('sha256', 'pa-me-' . microtime(true)),
            'source_snapshot_hash' => hash('sha256', 'pa-snap-' . microtime(true)),
            'dry_run_version' => 'pa-dry-run-v1',
            'inventory_status' => 'INVENTORIED',
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $glAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $this->property->id,
            'code' => 'PA-GL-' . Str::random(6),
            'name' => 'PilotAuth GL',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $controlledAccountId = (string) Str::ulid();
        DB::table('controlled_bank_accounts')->insert([
            'id' => $controlledAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => 'PilotAuth Ctrl Bank',
            'account_name' => 'PilotAuth Acct',
            'external_account_reference' => 'PA-EXT-' . Str::random(6),
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'pa-test',
            'registered_by' => $this->financeManager->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'pa-ca-' . microtime(true)),
            'source_snapshot' => json_encode(['test_scope' => 'pilot_auth']),
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
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
            'target_identity_hash' => hash('sha256', 'pa-th-' . microtime(true)),
            'status' => $status,
            'correlation_id' => (string) Str::ulid(),
            'proposal_actor_id' => $this->financeManager->id,
            'review_actor_id' => $this->otherFinanceController->id,
            'review_outcome' => $status === BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED ? 'REVIEW_ACCEPTED' : null,
            'review_timestamp' => $status === BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED ? $timestamp : null,
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
        ]);
    }

    private function requestPilotAuthorizationAs(User $actor, BankingMigrationTargetIntake $intake): BankingMigrationPilotAuthorization
    {
        setPermissionsTeamId($this->property->id);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationPilotAuthorizationService::class);

        return $service->request($intake->id, $actor);
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'PAA Company ' . $companySuffix,
            'slug' => 'paa-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'PAA Property ' . $companySuffix,
            'slug' => 'paa-property-' . $companySuffix,
            'code' => 'PAAP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'PAA Other ' . $companySuffix,
            'slug' => 'paa-other-' . $companySuffix,
            'code' => 'PAAO' . $companySuffix,
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
        Permission::firstOrCreate(['name' => 'finance.fx-adjustment.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.journal-entry.post', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.payables.ap-settlement.allocate', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.fx-adjustment-candidate.create', 'guard_name' => 'web']);

        $this->financeManager = User::create([
            'name' => 'PAA FM ' . $companySuffix,
            'email' => 'paa-fm-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->financeManager->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->financeManager->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->financeManager->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            BankingMigrationPlanService::PERMISSION_MANAGE,
        ]);

        $this->financeController = User::create([
            'name' => 'PAA FC ' . $companySuffix,
            'email' => 'paa-fc-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->financeController->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->financeController->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->financeController->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            'finance.banking.migration.mapping.review',
            'finance.banking.migration.pilot.authorization.review',
        ]);

        $this->otherFinanceController = User::create([
            'name' => 'PAA OFC ' . $companySuffix,
            'email' => 'paa-ofc-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->otherFinanceController->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->otherFinanceController->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            'finance.banking.migration.mapping.review',
            'finance.banking.migration.pilot.authorization.review',
        ]);

        $this->glAccountant = User::create([
            'name' => 'PAA GL ' . $companySuffix,
            'email' => 'paa-gl-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->glAccountant->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->glAccountant->givePermissionTo([
            'finance.fx-adjustment.view',
            'finance.journal-entry.post',
        ]);

        $this->apOfficer = User::create([
            'name' => 'PAA AP ' . $companySuffix,
            'email' => 'paa-ap-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->apOfficer->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->apOfficer->givePermissionTo([
            'finance.fx-adjustment.view',
            'finance.payables.ap-settlement.allocate',
            'finance.fx-adjustment-candidate.create',
        ]);

        $this->noPermUser = User::create([
            'name' => 'PAA None ' . $companySuffix,
            'email' => 'paa-none-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->noPermUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $timestamp = now();

        $legacyBankAccountId = (string) Str::ulid();
        DB::table('bank_accounts')->insert([
            'id' => $legacyBankAccountId,
            'property_id' => $this->property->id,
            'bank_name' => 'PAA Legacy Bank',
            'account_name' => 'PAA Legacy Acct',
            'account_number' => 'LEGACY-' . $companySuffix,
            'currency_code' => 'IDR',
            'opening_balance' => '500.00',
            'current_balance' => '500.00',
            'reconciled_balance' => '0.00',
            'is_active' => true,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
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
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $glAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $this->property->id,
            'code' => 'PAA-GL-' . $companySuffix,
            'name' => 'PAA GL',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $controlledBankAccountId = (string) Str::ulid();
        DB::table('controlled_bank_accounts')->insert([
            'id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => 'PAA Ctrl Bank',
            'account_name' => 'PAA Ctrl Acct',
            'external_account_reference' => 'PAA-EXT-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'paa-test',
            'registered_by' => $this->financeManager->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'paa-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'pilot_auth_action']),
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => (string) Str::ulid(),
            'controlled_bank_account_id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'paa-stmt-' . $companySuffix,
            'external_reference' => 'PAA-STMT-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => 'OUTFLOW',
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->financeManager->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'paa-stmt-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'pilot_auth_action']),
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
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
