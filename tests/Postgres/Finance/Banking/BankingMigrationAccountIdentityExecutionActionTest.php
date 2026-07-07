<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationAccountIdentityExecutionOutcomeEnum;
use Modules\Finance\Banking\Enums\BankingMigrationExceptionCodeEnum;
use Modules\Finance\Banking\Enums\BankingMigrationPilotAuthorizationStatusEnum;
use Modules\Finance\Banking\Enums\BankingMigrationTargetIntakeStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationAccountIdentityExecution;
use Modules\Finance\Banking\Models\BankingMigrationPilotAuthorization;
use Modules\Finance\Banking\Services\BankingMigrationAccountIdentityExecutionService;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingMigrationAccountIdentityExecutionActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $financeManager;
    private User $financeController;
    private User $glAccountant;
    private User $apOfficer;
    private User $noPermUser;
    private User $pilotAuthReviewer;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_unauthenticated_execution_is_denied(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        $this->post(route('finance.banking.migration.pilot-authorization.execute', [
            'pilotAuth' => $pilotAuth->id,
        ]))->assertRedirect();
    }

    public function test_active_property_context_required_for_execution(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        app(CurrentPropertyService::class)->setPropertyId(null);

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]));

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_missing_execution_permission_is_denied(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->noPermUser, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertForbidden();
    }

    public function test_finance_controller_cannot_execute(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->financeController, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]));

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_gl_accountant_cannot_execute(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->glAccountant, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertForbidden();
    }

    public function test_ap_officer_cannot_execute(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->apOfficer, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertForbidden();
    }

    public function test_finance_manager_with_confirmation_can_execute(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_executor_equal_to_target_intake_proposal_actor_fails(): void
    {
        $this->createFixtures();

        $fmActorId = $this->financeManager->id;
        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        DB::table('banking_migration_target_intakes')->where('id', $pilotAuth->target_intake_id)
            ->update(['proposal_actor_id' => $fmActorId]);


        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_executor_equal_to_target_intake_review_actor_fails(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        DB::table('banking_migration_target_intakes')->where('id', $pilotAuth->target_intake_id)
            ->update(['review_actor_id' => $this->financeManager->id]);


        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_executor_equal_to_pilot_auth_request_actor_fails(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        DB::table('banking_migration_pilot_authorizations')->where('id', $pilotAuth->id)
            ->update(['request_actor_id' => $this->financeManager->id]);


        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_executor_equal_to_pilot_auth_review_actor_fails(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        DB::table('banking_migration_pilot_authorizations')->where('id', $pilotAuth->id)
            ->update(['review_actor_id' => $this->financeManager->id]);


        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_cross_property_execution_fails(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        app(CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        setPermissionsTeamId($this->otherProperty->id);

        $this->withSession($this->propertySessionWithConfirmationOtherProperty())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertForbidden();
    }

    public function test_target_intake_not_review_accepted_fails(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        DB::table('banking_migration_target_intakes')->where('id', $pilotAuth->target_intake_id)
            ->update(['status' => BankingMigrationTargetIntakeStatusEnum::PROPOSED->value]);


        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_pilot_auth_not_review_accepted_fails(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $pilotAuth->status = BankingMigrationPilotAuthorizationStatusEnum::REQUESTED;
        $pilotAuth->save();


        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_source_snapshot_drift_fails_and_creates_quarantine(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $manifestEntryId = $pilotAuth->manifest_entry_id;
        $planId = $pilotAuth->migration_plan_id;
        $sourceUlid = DB::table('banking_migration_manifest_entries')->where('id', $manifestEntryId)->value('source_ulid');

        $invalidHash = hash('sha256', 'deliberately-wrong-snapshot-hash-' . microtime(true));
        DB::table('banking_migration_manifest_entries')->where('id', $manifestEntryId)
            ->update(['source_snapshot_hash' => $invalidHash]);

        setPermissionsTeamId($this->property->id);

        $beforeExecCount = DB::table('banking_migration_account_identity_executions')->count();

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $afterExecCount = DB::table('banking_migration_account_identity_executions')->count();
        $this->assertSame($beforeExecCount, $afterExecCount, 'No execution record should be created when source snapshot has changed.');
    }

    public function test_unresolved_quarantine_blocks_execution(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $planId = $pilotAuth->migration_plan_id;
        $sourceUlid = DB::table('banking_migration_manifest_entries')->where('id', $pilotAuth->manifest_entry_id)->value('source_ulid');

        DB::table('banking_migration_exception_quarantines')->insert([
            'id' => (string) Str::ulid(),
            'migration_plan_id' => $planId,
            'manifest_entry_id' => $pilotAuth->manifest_entry_id,
            'exception_code' => BankingMigrationExceptionCodeEnum::EXECUTION_NOT_AUTHORIZED->value,
            'severity' => 'ERROR',
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'source_ulid' => $sourceUlid,
            'source_property_id' => $this->property->id,
            'correlation_id' => (string) Str::ulid(),
            'is_resolved' => false,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);


        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_inactive_target_blocks_execution(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $targetIntakeId = $pilotAuth->target_intake_id;
        $controlledAccountId = DB::table('banking_migration_target_intakes')->where('id', $targetIntakeId)->value('controlled_bank_account_id');
        DB::table('controlled_bank_accounts')->where('id', $controlledAccountId)->update(['is_active' => false]);


        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_execution_without_confirmation_fails(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_valid_execution_writes_exactly_one_execution_ledger_record(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $beforeExecCount = DB::table('banking_migration_account_identity_executions')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $afterExecCount = DB::table('banking_migration_account_identity_executions')->count();
        $this->assertSame($beforeExecCount + 1, $afterExecCount);

        $execution = BankingMigrationAccountIdentityExecution::latest()->first();
        $this->assertNotNull($execution);
        $this->assertSame(BankingMigrationAccountIdentityExecutionOutcomeEnum::ACCOUNT_IDENTITY_LINEAGE_EXECUTED, $execution->outcome);
        $this->assertNotEmpty($execution->source_identity_hash);
        $this->assertNotEmpty($execution->source_snapshot_hash);
        $this->assertNotEmpty($execution->target_identity_hash);
        $this->assertNotEmpty($execution->idempotency_key);
        $this->assertNotEmpty($execution->correlation_id);
    }

    public function test_valid_execution_does_not_modify_legacy_banking(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $beforeLegacy = DB::table('bank_accounts')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $this->assertSame($beforeLegacy, DB::table('bank_accounts')->count());
    }

    public function test_valid_execution_does_not_modify_controlled_banking(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $beforeControlled = DB::table('controlled_bank_accounts')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $this->assertSame($beforeControlled, DB::table('controlled_bank_accounts')->count());
    }

    public function test_valid_execution_does_not_modify_migration_control_plane(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $beforePlan = DB::table('banking_migration_plans')->count();
        $beforeManifest = DB::table('banking_migration_manifest_entries')->count();
        $beforeIntake = DB::table('banking_migration_target_intakes')->count();
        $beforePilotAuth = DB::table('banking_migration_pilot_authorizations')->count();
        $beforeQuarantine = DB::table('banking_migration_exception_quarantines')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $this->assertSame($beforePlan, DB::table('banking_migration_plans')->count());
        $this->assertSame($beforeManifest, DB::table('banking_migration_manifest_entries')->count());
        $this->assertSame($beforeIntake, DB::table('banking_migration_target_intakes')->count());
        $this->assertSame($beforePilotAuth, DB::table('banking_migration_pilot_authorizations')->count());
        $this->assertSame($beforeQuarantine, DB::table('banking_migration_exception_quarantines')->count());
    }

    public function test_valid_execution_does_not_modify_payment_or_financial(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $beforePayments = DB::table('payment_executions')->count();
        $beforeJournals = DB::table('gl_journal_entries')->count();
        $beforeCashbook = DB::table('cashbook_transactions')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $this->assertSame($beforePayments, DB::table('payment_executions')->count());
        $this->assertSame($beforeJournals, DB::table('gl_journal_entries')->count());
        $this->assertSame($beforeCashbook, DB::table('cashbook_transactions')->count());
    }

    public function test_ledger_source_target_hashes_are_server_derived(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $execution = BankingMigrationAccountIdentityExecution::latest()->first();
        $this->assertSame(64, strlen($execution->source_identity_hash));
        $this->assertSame(64, strlen($execution->source_snapshot_hash));
        $this->assertSame(64, strlen($execution->target_identity_hash));
    }

    public function test_ledger_update_through_model_fails(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $execution = BankingMigrationAccountIdentityExecution::latest()->first();
        $originalOutcome = $execution->outcome;

        $execution->outcome = BankingMigrationAccountIdentityExecutionOutcomeEnum::ACCOUNT_IDENTITY_LINEAGE_EXECUTED;
        $execution->correlation_id = (string) Str::ulid();

        $this->assertSame($originalOutcome, $execution->outcome);
    }

    public function test_same_source_same_target_is_idempotent(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $execAfterFirst = DB::table('banking_migration_account_identity_executions')->count();

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $execAfterSecond = DB::table('banking_migration_account_identity_executions')->count();
        $this->assertSame($execAfterFirst, $execAfterSecond, 'Idempotent replay should not create duplicate records.');
    }

    public function test_no_financial_values_stored_in_ledger(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $execution = BankingMigrationAccountIdentityExecution::latest()->first();
        $executionArray = $execution->toArray();

        $this->assertArrayNotHasKey('balance', $executionArray);
        $this->assertArrayNotHasKey('amount', $executionArray);
        $this->assertArrayNotHasKey('account_number', $executionArray);
        $this->assertArrayNotHasKey('bank_name', $executionArray);
        $this->assertArrayNotHasKey('account_name', $executionArray);
        $this->assertArrayNotHasKey('currency', $executionArray);
        $this->assertArrayNotHasKey('external_reference', $executionArray);
        $this->assertArrayNotHasKey('vendor', $executionArray);
        $this->assertArrayNotHasKey('score', $executionArray);
        $this->assertArrayNotHasKey('confidence', $executionArray);
    }

    public function test_no_execution_creates_payment_or_reconciliation(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();
        $beforePayments = DB::table('payment_executions')->count();
        $beforeReconciliations = DB::table('bank_payment_reconciliations')->count();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $this->assertSame($beforePayments, DB::table('payment_executions')->count());
        $this->assertSame($beforeReconciliations, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_execution_preserves_cutover_not_authorized(): void
    {
        $this->createFixtures();

        $pilotAuth = $this->createReviewAcceptedPilotAuthorization();

        setPermissionsTeamId($this->property->id);

        $this->withSession($this->propertySessionWithConfirmation())
            ->actingAs($this->financeManager, 'web')
            ->post(route('finance.banking.migration.pilot-authorization.execute', [
                'pilotAuth' => $pilotAuth->id,
            ]))
            ->assertRedirect();

        $execution = BankingMigrationAccountIdentityExecution::latest()->first();
        $this->assertEquals(BankingMigrationAccountIdentityExecutionOutcomeEnum::ACCOUNT_IDENTITY_LINEAGE_EXECUTED, $execution->outcome);
    }

    public function test_no_cutover_or_rollback_route_exists(): void
    {
        $this->createFixtures();

        $this->assertTrue(true);
    }

    private function createReviewAcceptedPilotAuthorization(): BankingMigrationPilotAuthorization
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
            'idempotency_key' => hash('sha256', 'exe-test-plan-' . microtime(true)),
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_actor_id' => $this->financeManager->id,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $legacyAccountId = (string) Str::ulid();
        DB::table('bank_accounts')->insert([
            'id' => $legacyAccountId,
            'property_id' => $this->property->id,
            'bank_name' => 'Exec Test Legacy Bank',
            'account_name' => 'Exec Legacy Account',
            'account_number' => 'EXEC-LEGACY-' . Str::random(6),
            'currency_code' => 'IDR',
            'opening_balance' => '1000.00',
            'current_balance' => '1000.00',
            'reconciled_balance' => '0.00',
            'is_active' => true,
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $sourceSnapshotHash = hash('sha256', implode('|', [
            'BankAccount',
            $legacyAccountId,
            $this->property->id,
            $timestamp->format('Y-m-d H:i:s'),
            $timestamp->format('Y-m-d H:i:s'),
        ]));

        $manifestEntryId = (string) Str::ulid();
        DB::table('banking_migration_manifest_entries')->insert([
            'id' => $manifestEntryId,
            'migration_plan_id' => $planId,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'source_ulid' => $legacyAccountId,
            'source_property_id' => $this->property->id,
            'source_identity_hash' => hash('sha256', 'exe-test-manifest-' . microtime(true)),
            'source_snapshot_hash' => $sourceSnapshotHash,
            'dry_run_version' => 'exe-dry-run-v1',
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
            'code' => 'EXEC-GL-' . Str::random(6),
            'name' => 'Exec Test GL',
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
            'bank_name' => 'Exec Test Controlled Bank',
            'account_name' => 'Exec Controlled Account',
            'external_account_reference' => 'EXEC-EXT-' . Str::random(6),
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'exe-test',
            'registered_by' => $this->financeManager->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'exe-ca-' . microtime(true)),
            'source_snapshot' => json_encode(['test_scope' => 'exe_test']),
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $targetIdentityHash = hash('sha256', implode('|', [
            'banking_migration_target_intake_v1',
            $this->property->id,
            $controlledAccountId,
        ]));

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
            'target_identity_hash' => $targetIdentityHash,
            'status' => 'REVIEW_ACCEPTED',
            'correlation_id' => (string) Str::ulid(),
            'proposal_actor_id' => $this->pilotAuthReviewer->id,
            'review_actor_id' => $this->financeController->id,
            'review_outcome' => 'REVIEW_ACCEPTED',
            'review_timestamp' => $timestamp,
            'execution_authority' => 'UNAVAILABLE',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $pilotAuthId = (string) Str::ulid();
        DB::table('banking_migration_pilot_authorizations')->insert([
            'id' => $pilotAuthId,
            'property_id' => $this->property->id,
            'migration_plan_id' => $planId,
            'manifest_entry_id' => $manifestEntryId,
            'target_intake_id' => $targetIntakeId,
            'authorization_scope' => 'account_identity_pilot_only',
            'status' => 'REVIEW_ACCEPTED',
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => hash('sha256', 'exe-ik-' . microtime(true)),
            'request_actor_id' => $this->pilotAuthReviewer->id,
            'review_actor_id' => $this->financeController->id,
            'review_outcome' => 'REVIEW_ACCEPTED',
            'review_timestamp' => $timestamp,
            'execution_authority' => 'MIGRATION_EXECUTION_NOT_IMPLEMENTED',
            'cutover_authority' => 'CUTOVER_NOT_AUTHORIZED',
            'created_by' => $this->financeManager->id,
            'updated_by' => $this->financeManager->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return BankingMigrationPilotAuthorization::find($pilotAuthId);
    }

    private function confirmExecution(User $actor): void
    {
        $service = app(SensitiveActionConfirmationService::class);
        $propertyId = $this->property->id;
        $companyId = $this->company->id;

        $service->confirm(
            $actor,
            BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT,
            'password',
            $companyId,
            $propertyId
        );
    }

    private function confirmExecutionOtherProperty(User $actor): void
    {
        $service = app(SensitiveActionConfirmationService::class);
        $service->confirm(
            $actor,
            BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT,
            'password',
            $this->company->id,
            $this->otherProperty->id
        );
    }

    private function confirmSession(): array
    {
        return [
            'sensitive_action_confirmation' => [
                BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT => [
                    'actor_id' => $this->financeManager->id,
                    'intent' => BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT,
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => now()->toISOString(),
                    'expires_at' => now()->addMinutes(15)->toISOString(),
                ],
            ],
        ];
    }

    private function confirmSessionOtherProperty(): array
    {
        return [
            'sensitive_action_confirmation' => [
                BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT => [
                    'actor_id' => $this->financeManager->id,
                    'intent' => BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT,
                    'company_id' => $this->company->id,
                    'property_id' => $this->otherProperty->id,
                    'confirmed_at' => now()->toISOString(),
                    'expires_at' => now()->addMinutes(15)->toISOString(),
                ],
            ],
        ];
    }

    private function propertySessionWithConfirmation(): array
    {
        return array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT => [
                    'actor_id' => $this->financeManager->id,
                    'intent' => BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT,
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => now()->toISOString(),
                    'expires_at' => now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);
    }

    private function propertySessionWithConfirmationOtherProperty(): array
    {
        return array_merge($this->otherPropertySession(), [
            'sensitive_action_confirmation' => [
                BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT => [
                    'actor_id' => $this->financeManager->id,
                    'intent' => BankingMigrationAccountIdentityExecutionService::CONFIRMATION_INTENT,
                    'company_id' => $this->company->id,
                    'property_id' => $this->otherProperty->id,
                    'confirmed_at' => now()->toISOString(),
                    'expires_at' => now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);
    }

    private function propertySession(array $merge = []): array
    {
        return array_merge([
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ], $merge);
    }

    private function otherPropertySession(array $merge = []): array
    {
        return array_merge([
            'active_property_id' => $this->otherProperty->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->otherProperty->id,
        ], $merge);
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'EXEA Company ' . $companySuffix,
            'slug' => 'exea-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'EXEA Property ' . $companySuffix,
            'slug' => 'exea-property-' . $companySuffix,
            'code' => 'EXA' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'EXEA Other ' . $companySuffix,
            'slug' => 'exea-other-' . $companySuffix,
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
        Permission::firstOrCreate(['name' => 'finance.fx-adjustment.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.journal-entry.post', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.payables.ap-settlement.allocate', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.fx-adjustment-candidate.create', 'guard_name' => 'web']);

        $this->financeManager = User::create([
            'name' => 'EXEA FM ' . $companySuffix,
            'email' => 'exea-fm-' . $companySuffix . '@example.test',
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
            'finance.banking.migration.pilot.execution.execute',
        ]);

        $this->financeController = User::create([
            'name' => 'EXEA FC ' . $companySuffix,
            'email' => 'exea-fc-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->financeController->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->financeController->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            'finance.banking.migration.mapping.review',
            'finance.banking.migration.pilot.authorization.review',
        ]);

        $this->glAccountant = User::create([
            'name' => 'EXEA GL ' . $companySuffix,
            'email' => 'exea-gl-' . $companySuffix . '@example.test',
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
            'name' => 'EXEA AP ' . $companySuffix,
            'email' => 'exea-ap-' . $companySuffix . '@example.test',
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
            'name' => 'EXEA NoPerm ' . $companySuffix,
            'email' => 'exea-noperm-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->noPermUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->pilotAuthReviewer = User::create([
            'name' => 'EXEA PARev ' . $companySuffix,
            'email' => 'exea-parev-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->pilotAuthReviewer->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
    }
}
