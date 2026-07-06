<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankPaymentReconciliationStatusEnum;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Services\ManualBankReconciliationService;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Services\PaymentExecutionService;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class BankReconciliationWebActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $reconciler;
    private User $otherActor;
    private User $noAuthUser;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => ManualBankReconciliationService::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => PaymentExecutionService::PERMISSION, 'guard_name' => 'web']);
    }

    public function test_unauthenticated_cannot_reconcile(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->post(route('finance.banking.bank-reconciliation.reconcile'), [
            'posted_journal_entry_id' => $context['posted_journal_entry_id'],
            'controlled_bank_statement_line_id' => $context['statement_line_id'],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_actor_without_permission_receives_403(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.banking.bank-reconciliation.reconcile'), [
                'posted_journal_entry_id' => $context['posted_journal_entry_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertStatus(403);

        $this->assertSame(0, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_cross_property_target_fails_closed(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->reconciler, 'web')
            ->post(route('finance.banking.bank-reconciliation.reconcile'), [
                'posted_journal_entry_id' => $context['posted_journal_entry_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertStatus(404);

        $this->assertSame(0, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_browser_injected_amount_is_ignored(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->reconciler, 'web')
            ->post(route('finance.banking.bank-reconciliation.reconcile'), [
                'posted_journal_entry_id' => $context['posted_journal_entry_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
                'amount' => '9999.99',
                'currency_code' => 'USD',
                'property_id' => $this->otherProperty->id,
                'balance' => '100000.00',
                'difference' => '0.00',
                'status' => 'FORCED',
            ])->assertRedirect()
            ->assertSessionHas('success');

        $rec = DB::table('bank_payment_reconciliations')->first();
        $this->assertSame('75.00', $rec->payment_amount);
        $this->assertSame('75.00', $rec->statement_amount);
        $this->assertSame('0.00', $rec->difference_amount);
        $this->assertSame($this->property->id, $rec->property_id);
        $this->assertSame($this->reconciler->id, $rec->reconciled_by);
    }

    public function test_valid_reconciliation_succeeds(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->reconciler, 'web')
            ->post(route('finance.banking.bank-reconciliation.reconcile'), [
                'posted_journal_entry_id' => $context['posted_journal_entry_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, DB::table('bank_payment_reconciliations')->count());

        $rec = DB::table('bank_payment_reconciliations')->first();
        $this->assertSame($this->property->id, $rec->property_id);
        $this->assertSame($context['bank_account_id'], $rec->controlled_bank_account_id);
        $this->assertSame($context['statement_line_id'], $rec->controlled_bank_statement_line_id);
        $this->assertSame($context['execution_id'], $rec->payment_execution_id);
        $this->assertSame($context['posted_journal_entry_id'], $rec->posted_journal_entry_id);
        $this->assertSame('75.00', $rec->payment_amount);
        $this->assertSame('75.00', $rec->statement_amount);
        $this->assertSame('0.00', $rec->difference_amount);
        $this->assertSame(BankPaymentReconciliationStatusEnum::RECONCILED->value, $rec->status);
        $this->assertSame($this->reconciler->id, $rec->reconciled_by);
    }

    public function test_idempotent_replay_preserves_reconciliation(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $this->withSession($this->propertySession())
            ->actingAs($this->reconciler, 'web')
            ->post(route('finance.banking.bank-reconciliation.reconcile'), [
                'posted_journal_entry_id' => $context['posted_journal_entry_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect();

        $first = DB::table('bank_payment_reconciliations')->first();

        $this->withSession($this->propertySession())
            ->actingAs($this->reconciler, 'web')
            ->post(route('finance.banking.bank-reconciliation.reconcile'), [
                'posted_journal_entry_id' => $context['posted_journal_entry_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect();

        $this->assertSame(1, DB::table('bank_payment_reconciliations')->count());
        $replay = DB::table('bank_payment_reconciliations')->first();
        $this->assertSame($first->id, $replay->id);
    }

    public function test_no_role_or_permission_mutation(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $roleCountBefore = DB::table('model_has_roles')->where('model_id', $this->reconciler->id)->count();
        $permCountBefore = DB::table('model_has_permissions')->where('model_id', $this->reconciler->id)->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->reconciler, 'web')
            ->post(route('finance.banking.bank-reconciliation.reconcile'), [
                'posted_journal_entry_id' => $context['posted_journal_entry_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')->where('model_id', $this->reconciler->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')->where('model_id', $this->reconciler->id)->count());
    }

    public function test_no_cashbook_or_cash_session_mutation(): void
    {
        $this->createFixtures();
        $context = $this->makeReconciliationContext();

        $beforeCashbook = DB::table('cashbook_transactions')->count();
        $beforeSessions = DB::table('cashier_sessions')->count();
        $beforeExecutions = DB::table('payment_executions')->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->reconciler, 'web')
            ->post(route('finance.banking.bank-reconciliation.reconcile'), [
                'posted_journal_entry_id' => $context['posted_journal_entry_id'],
                'controlled_bank_statement_line_id' => $context['statement_line_id'],
            ])->assertRedirect();

        $this->assertSame($beforeCashbook, DB::table('cashbook_transactions')->count());
        $this->assertSame($beforeSessions, DB::table('cashier_sessions')->count());
        $execution = DB::table('payment_executions')->first();
        $this->assertNotNull($execution);
    }

    private function createFixtures(): void
    {
        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'Bank Recon Web Company ' . $suffix,
            'slug' => 'bank-recon-web-company-' . $suffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Bank Recon Web Property ' . $suffix,
            'slug' => 'bank-recon-web-property-' . $suffix,
            'code' => 'BRW' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Bank Recon Web Other ' . $suffix,
            'slug' => 'bank-recon-web-other-' . $suffix,
            'code' => 'BRO' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->reconciler = $this->user('Bank Reconciler ' . $suffix, 'bank-reconciler-' . $suffix . '@example.test');
        $this->reconciler->givePermissionTo(ManualBankReconciliationService::PERMISSION);
        $this->attachProperty($this->reconciler, $this->property);
        $this->attachProperty($this->reconciler, $this->otherProperty);

        $this->otherActor = $this->user('Bank Recon Other ' . $suffix, 'bank-recon-other-' . $suffix . '@example.test');
        $this->attachProperty($this->otherActor, $this->property);

        $this->noAuthUser = $this->user('Bank Recon NoAuth ' . $suffix, 'bank-recon-noauth-' . $suffix . '@example.test');
        $this->attachProperty($this->noAuthUser, $this->property);
    }

    private function makeReconciliationContext(): array
    {
        $timestamp = now();
        $vendorId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $sourceCandidateId = (string) Str::ulid();
        $apJournalEntryId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $proposalItemId = (string) Str::ulid();
        $sessionId = (string) Str::ulid();
        $instrumentId = (string) Str::ulid();
        $bankGlAccountId = (string) Str::ulid();
        $apAccountId = (string) Str::ulid();
        $bankAccountId = (string) Str::ulid();
        $statementLineId = (string) Str::ulid();
        $executionId = (string) Str::ulid();
        $paymentCandidateId = (string) Str::ulid();
        $postedPaymentJournalEntryId = (string) Str::ulid();
        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        DB::table('gl_accounts')->insert([
            'id' => $apAccountId,
            'property_id' => $this->property->id,
            'code' => 'AP-RECON-' . $suffix,
            'name' => 'AP Account',
            'normal_balance' => 'Credit',
            'account_type' => 'Liability',
            'account_category' => 'CurrentLiability',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_accounts')->insert([
            'id' => $bankGlAccountId,
            'property_id' => $this->property->id,
            'code' => 'BANK-GL-' . $suffix,
            'name' => 'Bank GL Account',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => true,
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendor_categories')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'category_code' => 'VC-' . $suffix,
            'name' => 'Recon Test Category',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $this->property->id,
            'company_id' => $this->company->id,
            'vendor_category_id' => DB::table('vendor_categories')->first()->id,
            'vendor_code' => 'V-' . $suffix,
            'name' => 'Recon Vendor ' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'AP source for bank recon web',
            'approved_by' => $this->reconciler->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'bank_recon_web']),
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $apJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'AP-RECON-JRNL-' . $suffix,
            'description' => 'Posted AP liability for bank recon web',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'Payables',
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'journal_candidate_id' => $sourceCandidateId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'posted_by' => null,
            'posted_at' => null,
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $apAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => '75.00',
                'memo' => 'Credit AP',
                'created_by' => $this->reconciler->id,
                'updated_by' => $this->reconciler->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $apAccountId,
                'debit_amount' => '75.00',
                'credit_amount' => '0.00',
                'memo' => 'Debit inventory',
                'created_by' => $this->reconciler->id,
                'updated_by' => $this->reconciler->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $apJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'posted_by' => $this->reconciler->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->reconciler->id,
                'updated_at' => $timestamp,
            ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'BRW-PROP-' . $suffix,
            'currency_code' => 'IDR',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', $apJournalEntryId),
            'total_amount' => '75.00',
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => $proposalItemId,
            'payment_proposal_id' => $proposalId,
            'property_id' => $this->property->id,
            'source_journal_entry_id' => $apJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => '75.00',
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'bank_recon_web']),
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_accounts')->insert([
            'id' => $bankAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $bankGlAccountId,
            'bank_name' => 'Recon Test Bank ' . $suffix,
            'account_name' => 'Recon Operating Account',
            'external_account_reference' => 'RECON-EXT-' . $suffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'recon-web-test',
            'registered_by' => $this->reconciler->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'recon-web-account-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'bank_recon_web']),
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => $statementLineId,
            'controlled_bank_account_id' => $bankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'recon-web-stmt-' . $suffix,
            'external_reference' => 'RECON-STMT-' . $suffix,
            'statement_date' => '2026-07-01',
            'direction' => ControlledBankStatementLineDirectionEnum::OUTFLOW->value,
            'amount' => '75.00',
            'currency_code' => 'IDR',
            'vendor_reference' => $vendorId,
            'recorded_by' => $this->reconciler->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'recon-web-stmt-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'bank_recon_web']),
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cashier_sessions')->insert([
            'id' => $sessionId,
            'property_id' => $this->property->id,
            'cashier_user_id' => $this->reconciler->id,
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_by' => $this->reconciler->id,
            'opened_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cashier_payment_instruments')->insert([
            'id' => $instrumentId,
            'property_id' => $this->property->id,
            'name' => 'Bank Recon Instrument ' . $suffix,
            'type' => CashierPaymentInstrumentTypeEnum::BANK->value,
            'operational_gl_account_id' => $bankGlAccountId,
            'is_active' => true,
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_executions')->insert([
            'id' => $executionId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'payment_proposal_id' => $proposalId,
            'payment_proposal_item_id' => $proposalItemId,
            'source_journal_entry_id' => $apJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => $sessionId,
            'cashier_payment_instrument_id' => $instrumentId,
            'operational_gl_account_id' => $bankGlAccountId,
            'controlled_bank_account_id' => $bankAccountId,
            'controlled_bank_statement_line_id' => $statementLineId,
            'currency_code' => 'IDR',
            'source_amount' => '75.00',
            'executed_by' => $this->reconciler->id,
            'executed_at' => $timestamp,
            'source_snapshot' => json_encode(['controlled_bank_statement_line_id' => $statementLineId]),
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $paymentCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'PaymentExecution',
            'source_id' => $executionId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Posted BANK payment candidate',
            'approved_by' => $this->reconciler->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'bank_recon_web']),
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $postedPaymentJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'BANK-PAY-POST-' . $suffix,
            'description' => 'Posted BANK payment fixture',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'GeneralCashier',
            'source_type' => 'PaymentExecution',
            'source_id' => $executionId,
            'journal_candidate_id' => $paymentCandidateId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'posted_by' => null,
            'posted_at' => null,
            'created_by' => $this->reconciler->id,
            'updated_by' => $this->reconciler->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $postedPaymentJournalEntryId,
                'account_id' => $apAccountId,
                'debit_amount' => '75.00',
                'credit_amount' => '0.00',
                'memo' => 'Debit AP',
                'created_by' => $this->reconciler->id,
                'updated_by' => $this->reconciler->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $postedPaymentJournalEntryId,
                'account_id' => $bankGlAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => '75.00',
                'memo' => 'Credit bank',
                'created_by' => $this->reconciler->id,
                'updated_by' => $this->reconciler->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $postedPaymentJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'posted_by' => $this->reconciler->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->reconciler->id,
                'updated_at' => $timestamp,
            ]);

        return [
            'bank_account_id' => $bankAccountId,
            'statement_line_id' => $statementLineId,
            'execution_id' => $executionId,
            'posted_journal_entry_id' => $postedPaymentJournalEntryId,
        ];
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function attachProperty(User $user, Property $property): void
    {
        $user->properties()->syncWithoutDetaching([
            $property->id => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ],
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
}
