<?php

namespace Tests\Postgres\Finance\Banking;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationTargetIntakeStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Finance\Banking\Services\BankingMigrationPlanService;
use Modules\Finance\Banking\Services\BankingMigrationTargetIntakeService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingMigrationTargetIntakeActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $fmUser;
    private User $fcUser;
    private User $glUser;
    private User $cashierUser;
    private string $planId;
    private string $manifestEntryId;
    private string $controlledAccountId;
    private string $inactiveAccountId;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function createActionFixtures(): void
    {
        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'TIA Company ' . $suffix,
            'slug' => 'tia-company-' . $suffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'TIA Property ' . $suffix,
            'slug' => 'tia-property-' . $suffix,
            'code' => 'TIAP' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'TIA Other ' . $suffix,
            'slug' => 'tia-other-' . $suffix,
            'code' => 'TIAO' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_VIEW, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => BankingMigrationPlanService::PERMISSION_MANAGE, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.banking.migration.mapping.review', 'guard_name' => 'web']);

        $this->fmUser = User::create([
            'name' => 'TIA FM ' . $suffix,
            'email' => 'tia-fm-' . $suffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->fmUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->fmUser->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            BankingMigrationPlanService::PERMISSION_MANAGE,
        ]);

        $this->fcUser = User::create([
            'name' => 'TIA FC ' . $suffix,
            'email' => 'tia-fc-' . $suffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->fcUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->fcUser->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            'finance.banking.migration.mapping.review',
        ]);

        $this->glUser = User::create([
            'name' => 'TIA GL ' . $suffix,
            'email' => 'tia-gl-' . $suffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->glUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->cashierUser = User::create([
            'name' => 'TIA Cashier ' . $suffix,
            'email' => 'tia-cashier-' . $suffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->cashierUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $timestamp = now();

        $planSvc = app(BankingMigrationPlanService::class);
        $plan = $planSvc->createPlan('tia-plan-' . $suffix, $this->fmUser);
        $this->planId = $plan->id;

        $legacyAccountId = (string) Str::ulid();
        DB::table('bank_accounts')->insert([
            'id' => $legacyAccountId,
            'property_id' => $this->property->id,
            'bank_name' => 'TIA Legacy Bank ' . $suffix,
            'account_name' => 'Legacy Account',
            'account_number' => 'TIA-LEGACY-' . $suffix,
            'currency_code' => 'IDR',
            'opening_balance' => '1000.00',
            'current_balance' => '1000.00',
            'reconciled_balance' => '0.00',
            'is_active' => true,
            'created_by' => $this->fmUser->id,
            'updated_by' => $this->fmUser->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->manifestEntryId = (string) Str::ulid();
        DB::table('banking_migration_manifest_entries')->insert([
            'id' => $this->manifestEntryId,
            'migration_plan_id' => $this->planId,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankAccount',
            'source_ulid' => $legacyAccountId,
            'source_property_id' => $this->property->id,
            'source_identity_hash' => hash('sha256', 'tia-me-' . $suffix),
            'source_snapshot_hash' => hash('sha256', 'tia-snap-' . $suffix),
            'dry_run_version' => 'tia-drv1',
            'inventory_status' => 'INVENTORIED',
            'created_by' => $this->fmUser->id,
            'updated_by' => $this->fmUser->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $glAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $this->property->id,
            'code' => 'TIA-GL-' . $suffix,
            'name' => 'TIA GL Account',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->fmUser->id,
            'updated_by' => $this->fmUser->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->controlledAccountId = (string) Str::ulid();
        DB::table('controlled_bank_accounts')->insert([
            'id' => $this->controlledAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => 'TIA Controlled Bank ' . $suffix,
            'account_name' => 'Controlled Account',
            'external_account_reference' => 'TIA-EXT-' . $suffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'tia-test',
            'registered_by' => $this->fmUser->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'tia-ca-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'target_intake_action']),
            'created_by' => $this->fmUser->id,
            'updated_by' => $this->fmUser->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->inactiveAccountId = (string) Str::ulid();
        $glAccountId2 = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId2,
            'property_id' => $this->property->id,
            'code' => 'TIA-GL-2-' . $suffix,
            'name' => 'TIA GL Account 2',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->fmUser->id,
            'updated_by' => $this->fmUser->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('controlled_bank_accounts')->insert([
            'id' => $this->inactiveAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $glAccountId2,
            'bank_name' => 'TIA Inactive Bank ' . $suffix,
            'account_name' => 'Inactive Account',
            'external_account_reference' => 'TIA-INACTIVE-' . $suffix,
            'currency_code' => 'IDR',
            'is_active' => false,
            'source_reference' => 'tia-test-inactive',
            'registered_by' => $this->fmUser->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'tia-ca-inactive-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'target_intake_action_inactive']),
            'created_by' => $this->fmUser->id,
            'updated_by' => $this->fmUser->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function test_finance_manager_can_propose(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->assertNotNull($intake);
        $this->assertSame(BankingMigrationTargetIntakeStatusEnum::PROPOSED, $intake->status);
        $this->assertSame($this->property->id, $intake->property_id);
        $this->assertSame($this->planId, $intake->migration_plan_id);
        $this->assertSame($this->manifestEntryId, $intake->manifest_entry_id);
        $this->assertSame($this->controlledAccountId, $intake->controlled_bank_account_id);
        $this->assertSame($this->fmUser->id, $intake->proposal_actor_id);
        $this->assertSame('UNAVAILABLE', $intake->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $intake->cutover_authority);
        $this->assertNotEmpty($intake->correlation_id);
    }

    public function test_finance_controller_cannot_propose(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Actor lacks migration plan management permission.');

        $service = app(BankingMigrationTargetIntakeService::class);
        $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fcUser
        );
    }

    public function test_finance_controller_can_review(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $reviewed = $service->review(
            $intake->id,
            'REVIEW_ACCEPTED',
            $this->fcUser
        );

        $this->assertSame(BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED, $reviewed->status);
        $this->assertSame($this->fcUser->id, $reviewed->review_actor_id);
        $this->assertSame('REVIEW_ACCEPTED', $reviewed->review_outcome);
        $this->assertNotNull($reviewed->review_timestamp);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $reviewed->cutover_authority);
        $this->assertSame('UNAVAILABLE', $reviewed->execution_authority);
    }

    public function test_finance_controller_can_reject(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $reviewed = $service->review(
            $intake->id,
            'REVIEW_REJECTED',
            $this->fcUser
        );

        $this->assertSame(BankingMigrationTargetIntakeStatusEnum::REVIEW_REJECTED, $reviewed->status);
        $this->assertSame('REVIEW_REJECTED', $reviewed->review_outcome);
    }

    public function test_proposer_cannot_review_own_proposal(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->fmUser->givePermissionTo('finance.banking.migration.mapping.review');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Maker-checker violation');

        $service->review(
            $intake->id,
            'REVIEW_ACCEPTED',
            $this->fmUser
        );
    }

    public function test_finance_manager_cannot_review(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $otherFmUser = User::create([
            'name' => 'TIA FM Other ' . Str::random(6),
            'email' => 'tia-fmo-' . Str::random(6) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $otherFmUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $otherFmUser->givePermissionTo([
            BankingMigrationPlanService::PERMISSION_VIEW,
            BankingMigrationPlanService::PERMISSION_MANAGE,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Actor lacks mapping review permission.');

        $service->review(
            $intake->id,
            'REVIEW_ACCEPTED',
            $otherFmUser
        );
    }

    public function test_general_ledger_accountant_cannot_propose(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->expectException(DomainException::class);

        $service = app(BankingMigrationTargetIntakeService::class);
        $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->glUser
        );
    }

    public function test_general_ledger_accountant_cannot_review(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->expectException(DomainException::class);

        $service->review(
            $intake->id,
            'REVIEW_ACCEPTED',
            $this->glUser
        );
    }

    public function test_cashier_cannot_propose(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->expectException(DomainException::class);

        $service = app(BankingMigrationTargetIntakeService::class);
        $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->cashierUser
        );
    }

    public function test_cashier_cannot_review(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->expectException(DomainException::class);

        $service->review(
            $intake->id,
            'REVIEW_ACCEPTED',
            $this->cashierUser
        );
    }

    public function test_cross_property_proposal_fails_closed(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Migration plan not found');

        $service = app(BankingMigrationTargetIntakeService::class);
        $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );
    }

    public function test_cross_property_review_fails_closed(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        app(CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Target intake not found');

        $service->review(
            $intake->id,
            'REVIEW_ACCEPTED',
            $this->fcUser
        );
    }

    public function test_inactive_controlled_account_fails_closed(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Controlled bank account not found');

        $service = app(BankingMigrationTargetIntakeService::class);
        $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->inactiveAccountId,
            $this->fmUser
        );
    }

    public function test_non_bank_account_manifest_entry_fails_closed(): void
    {
        $this->createActionFixtures();

        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $nonBankEntryId = (string) Str::ulid();
        DB::table('banking_migration_manifest_entries')->insert([
            'id' => $nonBankEntryId,
            'migration_plan_id' => $this->planId,
            'source_domain' => 'legacy_banking',
            'source_model' => 'BankStatementLine',
            'source_ulid' => (string) Str::ulid(),
            'source_property_id' => $this->property->id,
            'source_identity_hash' => hash('sha256', 'tia-nonbank-' . $suffix),
            'source_snapshot_hash' => hash('sha256', 'tia-nonbank-snap-' . $suffix),
            'dry_run_version' => 'tia-drv1',
            'inventory_status' => 'INVENTORIED',
            'created_by' => $this->fmUser->id,
            'updated_by' => $this->fmUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only BankAccount manifest entries');

        $service = app(BankingMigrationTargetIntakeService::class);
        $service->propose(
            $this->planId,
            $nonBankEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );
    }

    public function test_proposal_writes_only_target_intake_table(): void
    {
        $this->createActionFixtures();

        $beforeControlled = DB::table('controlled_bank_accounts')->count();
        $beforeLegacy = DB::table('bank_accounts')->count();
        $beforePlans = DB::table('banking_migration_plans')->count();
        $beforeEntries = DB::table('banking_migration_manifest_entries')->count();
        $beforeIntakes = DB::table('banking_migration_target_intakes')->count();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->assertNotNull($intake);
        $this->assertSame($beforeControlled, DB::table('controlled_bank_accounts')->count());
        $this->assertSame($beforeLegacy, DB::table('bank_accounts')->count());
        $this->assertSame($beforePlans, DB::table('banking_migration_plans')->count());
        $this->assertSame($beforeEntries, DB::table('banking_migration_manifest_entries')->count());
        $this->assertSame($beforeIntakes + 1, DB::table('banking_migration_target_intakes')->count());
    }

    public function test_review_writes_only_target_intake_table(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $beforeControlled = DB::table('controlled_bank_accounts')->count();
        $beforeLegacy = DB::table('bank_accounts')->count();
        $beforePlans = DB::table('banking_migration_plans')->count();
        $beforeEntries = DB::table('banking_migration_manifest_entries')->count();
        $beforeIntakes = DB::table('banking_migration_target_intakes')->count();

        $service->review(
            $intake->id,
            'REVIEW_ACCEPTED',
            $this->fcUser
        );

        $this->assertSame($beforeControlled, DB::table('controlled_bank_accounts')->count());
        $this->assertSame($beforeLegacy, DB::table('bank_accounts')->count());
        $this->assertSame($beforePlans, DB::table('banking_migration_plans')->count());
        $this->assertSame($beforeEntries, DB::table('banking_migration_manifest_entries')->count());
        $this->assertSame($beforeIntakes, DB::table('banking_migration_target_intakes')->count());
    }

    public function test_no_legacy_operational_banking_record_changed(): void
    {
        $this->createActionFixtures();

        $beforeLegacyAccountCount = DB::table('bank_accounts')->count();
        $beforeLegacySessionCount = DB::table('reconciliation_sessions')->count();
        $beforeLegacyMatchCount = DB::table('reconciliation_matches')->count();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $service->review(
            $intake->id,
            'REVIEW_ACCEPTED',
            $this->fcUser
        );

        $this->assertSame($beforeLegacyAccountCount, DB::table('bank_accounts')->count());
        $this->assertSame($beforeLegacySessionCount, DB::table('reconciliation_sessions')->count());
        $this->assertSame($beforeLegacyMatchCount, DB::table('reconciliation_matches')->count());
    }

    public function test_no_controlled_operational_banking_record_changed(): void
    {
        $this->createActionFixtures();

        $beforeControlledAccountCount = DB::table('controlled_bank_accounts')->count();
        $beforeControlledStmtCount = DB::table('controlled_bank_statement_lines')->count();
        $beforeReconciliationCount = DB::table('bank_payment_reconciliations')->count();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $service->review(
            $intake->id,
            'REVIEW_ACCEPTED',
            $this->fcUser
        );

        $this->assertSame($beforeControlledAccountCount, DB::table('controlled_bank_accounts')->count());
        $this->assertSame($beforeControlledStmtCount, DB::table('controlled_bank_statement_lines')->count());
        $this->assertSame($beforeReconciliationCount, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_duplicate_active_proposal_for_same_plan_and_manifest_is_prevented(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake1 = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $intake2 = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->assertSame($intake1->id, $intake2->id);

        $count = BankingMigrationTargetIntake::where('migration_plan_id', $this->planId)
            ->where('manifest_entry_id', $this->manifestEntryId)
            ->whereNotIn('status', [
                BankingMigrationTargetIntakeStatusEnum::ARCHIVED->value,
                BankingMigrationTargetIntakeStatusEnum::REVIEW_REJECTED->value,
            ])
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_review_outcome_cannot_be_replayed(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $service->review($intake->id, 'REVIEW_ACCEPTED', $this->fcUser);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only PROPOSED target intakes can be reviewed.');

        $service->review($intake->id, 'REVIEW_REJECTED', $this->fcUser);
    }

    public function test_browser_injected_fields_are_ignored(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->assertSame('legacy_banking', $intake->source_domain);
        $this->assertSame('controlled_banking', $intake->target_domain);
        $this->assertSame('ControlledBankAccount', $intake->target_model);
        $this->assertSame('UNAVAILABLE', $intake->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $intake->cutover_authority);
        $this->assertSame($this->fmUser->id, $intake->proposal_actor_id);
    }

    public function test_no_cross_domain_field_comparison_in_proposal(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->assertArrayNotHasKey('source_account_number', $intake->toArray());
        $this->assertArrayNotHasKey('target_account_number', $intake->toArray());
        $this->assertArrayNotHasKey('source_bank_name', $intake->toArray());
        $this->assertArrayNotHasKey('target_bank_name', $intake->toArray());
        $this->assertArrayNotHasKey('source_balance', $intake->toArray());
        $this->assertArrayNotHasKey('target_balance', $intake->toArray());
        $this->assertArrayNotHasKey('score', $intake->toArray());
        $this->assertArrayNotHasKey('confidence', $intake->toArray());
        $this->assertArrayNotHasKey('recommendation', $intake->toArray());
    }

    public function test_review_accepted_remains_non_executable(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $reviewed = $service->review($intake->id, 'REVIEW_ACCEPTED', $this->fcUser);

        $this->assertSame(BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED, $reviewed->status);
        $this->assertSame('UNAVAILABLE', $reviewed->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $reviewed->cutover_authority);

        $this->assertStringNotContainsString('MIGRATABLE', $reviewed->status->value);
        $this->assertStringNotContainsString('EXECUTED', $reviewed->status->value);
        $this->assertStringNotContainsString('READY', $reviewed->status->value);
    }

    public function test_no_execution_cutover_route_created(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->assertSame('UNAVAILABLE', $intake->execution_authority);
        $this->assertSame('CUTOVER_NOT_AUTHORIZED', $intake->cutover_authority);
    }

    public function test_unauthenticated_proposal_denied(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Authenticated actor is required.');

        $service = app(BankingMigrationTargetIntakeService::class);
        $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            null
        );
    }

    public function test_unauthenticated_review_denied(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Authenticated actor is required.');

        $service->review($intake->id, 'REVIEW_ACCEPTED', null);
    }

    public function test_invalid_review_outcome_fails(): void
    {
        $this->createActionFixtures();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Review outcome must be REVIEW_ACCEPTED or REVIEW_REJECTED.');

        $service->review($intake->id, 'INVALID_OUTCOME', $this->fcUser);
    }

    public function test_no_migration_plan_manifest_entry_or_exception_mutation(): void
    {
        $this->createActionFixtures();

        $beforePlans = DB::table('banking_migration_plans')->where('id', $this->planId)->first();
        $beforeEntries = DB::table('banking_migration_manifest_entries')->where('id', $this->manifestEntryId)->first();
        $beforeQuarantines = DB::table('banking_migration_exception_quarantines')->count();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $service->review($intake->id, 'REVIEW_ACCEPTED', $this->fcUser);

        $afterPlans = DB::table('banking_migration_plans')->where('id', $this->planId)->first();
        $afterEntries = DB::table('banking_migration_manifest_entries')->where('id', $this->manifestEntryId)->first();
        $afterQuarantines = DB::table('banking_migration_exception_quarantines')->count();

        $this->assertSame($beforePlans->status, $afterPlans->status);
        $this->assertSame($beforeEntries->inventory_status, $afterEntries->inventory_status);
        $this->assertSame($beforeQuarantines, $afterQuarantines);
    }

    public function test_no_payment_execution_journal_financial_period_mutation(): void
    {
        $this->createActionFixtures();

        $beforePaymentExecutions = DB::table('payment_executions')->count();
        $beforeJournals = DB::table('gl_journal_entries')->count();

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $service = app(BankingMigrationTargetIntakeService::class);
        $intake = $service->propose(
            $this->planId,
            $this->manifestEntryId,
            $this->controlledAccountId,
            $this->fmUser
        );

        $service->review($intake->id, 'REVIEW_ACCEPTED', $this->fcUser);

        $this->assertSame($beforePaymentExecutions, DB::table('payment_executions')->count());
        $this->assertSame($beforeJournals, DB::table('gl_journal_entries')->count());
    }
}
