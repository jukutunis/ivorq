<?php

namespace Tests\Postgres\Finance\Banking;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankPaymentReconciliationStatusEnum;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Services\ManualBankReconciliationService;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class ManualBankReconciliationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private string $apAccountId;
    private string $bankAccountId;
    private ManualBankReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);

        $this->apAccountId = $this->makeAccount('AP-RECON-' . $this->sequence++, 'Liability', 'CurrentLiability', 'Credit', false);
        $this->bankAccountId = $this->makeAccount('BANK-RECON-' . $this->sequence++, 'Asset', 'CurrentAsset', 'Debit', true);

        Permission::firstOrCreate([
            'name' => ManualBankReconciliationService::PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo(ManualBankReconciliationService::PERMISSION);

        $this->service = app(ManualBankReconciliationService::class);
    }

    public function test_manual_bank_reconciliation_records_immutable_exact_match_evidence(): void
    {
        $context = $this->makePostedBankPaymentContext('125.00');
        $before = $this->controlledSnapshot();

        $reconciliation = $this->service->reconcilePostedBankPayment(
            $context['posted_journal_entry_id'],
            $context['statement_line_id'],
            $this->actor
        );

        $this->assertSame($this->property->id, $reconciliation->property_id);
        $this->assertSame($context['controlled_bank_account_id'], $reconciliation->controlled_bank_account_id);
        $this->assertSame($context['statement_line_id'], $reconciliation->controlled_bank_statement_line_id);
        $this->assertSame($context['payment_execution_id'], $reconciliation->payment_execution_id);
        $this->assertSame($context['posted_journal_entry_id'], $reconciliation->posted_journal_entry_id);
        $this->assertSame('IDR', $reconciliation->currency_code);
        $this->assertSame('125.00', (string) $reconciliation->payment_amount);
        $this->assertSame('125.00', (string) $reconciliation->statement_amount);
        $this->assertSame('0.00', (string) $reconciliation->difference_amount);
        $this->assertSame(BankPaymentReconciliationStatusEnum::RECONCILED, $reconciliation->status);
        $this->assertSame($this->actor->id, $reconciliation->reconciled_by);
        $this->assertNotNull($reconciliation->reconciled_at);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'bank_payment_reconciliations' => 1,
        ]);

        $replay = $this->service->reconcilePostedBankPayment(
            $context['posted_journal_entry_id'],
            $context['statement_line_id'],
            $this->actor
        );
        $this->assertSame($reconciliation->id, $replay->id);
    }

    public function test_manual_bank_reconciliation_fails_closed_for_invalid_actor_and_mismatch(): void
    {
        $context = $this->makePostedBankPaymentContext('125.00');
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);

        try {
            $this->service->reconcilePostedBankPayment(
                $context['posted_journal_entry_id'],
                $context['statement_line_id'],
                $unauthorized
            );
            $this->fail('Unauthorized manual bank reconciliation must fail closed.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('bank_payment_reconciliations')->count());
        }

        $mismatchStatementLineId = $this->makeStatementLine($context['controlled_bank_account_id'], '126.00');

        try {
            $this->service->reconcilePostedBankPayment(
                $context['posted_journal_entry_id'],
                $mismatchStatementLineId,
                $this->actor
            );
            $this->fail('Manual bank reconciliation amount mismatch must fail closed.');
        } catch (DomainException) {
            $this->assertSame(0, DB::table('bank_payment_reconciliations')->count());
        }
    }

    private function makePostedBankPaymentContext(string $amount): array
    {
        $timestamp = now();
        $vendorId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $sourceCandidateId = (string) Str::ulid();
        $sourceApJournalEntryId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $proposalItemId = (string) Str::ulid();
        $cashierSessionId = (string) Str::ulid();
        $bankInstrumentId = (string) Str::ulid();
        $controlledBankAccountId = $this->makeControlledBankAccount();
        $statementLineId = $this->makeStatementLine($controlledBankAccountId, $amount);
        $paymentExecutionId = (string) Str::ulid();
        $paymentCandidateId = (string) Str::ulid();
        $postedPaymentJournalEntryId = (string) Str::ulid();
        $suffix = $this->sequence++;

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'AP source candidate for manual bank reconciliation',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'manual_bank_reconciliation']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $sourceApJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => '2026-07-01',
            'reference' => 'AP-BANK-RECON-' . $suffix,
            'description' => 'Posted AP liability fixture for bank reconciliation',
            'status' => JournalStatusEnum::Posted->value,
            'source_module' => 'Payables',
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'journal_candidate_id' => $sourceCandidateId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'posted_by' => $this->actor->id,
            'posted_at' => $timestamp,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'BANK-RECON-PROP-' . $suffix,
            'currency_code' => 'IDR',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', $sourceApJournalEntryId),
            'total_amount' => $amount,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => $proposalItemId,
            'payment_proposal_id' => $proposalId,
            'property_id' => $this->property->id,
            'source_journal_entry_id' => $sourceApJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'manual_bank_reconciliation']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cashier_sessions')->insert([
            'id' => $cashierSessionId,
            'property_id' => $this->property->id,
            'cashier_user_id' => $this->actor->id,
            'status' => 'OPEN',
            'opened_at' => $timestamp,
            'opened_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cashier_payment_instruments')->insert([
            'id' => $bankInstrumentId,
            'property_id' => $this->property->id,
            'name' => 'BANK reconciliation instrument ' . $suffix,
            'type' => 'BANK',
            'operational_gl_account_id' => $this->bankAccountId,
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_executions')->insert([
            'id' => $paymentExecutionId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'payment_proposal_id' => $proposalId,
            'payment_proposal_item_id' => $proposalItemId,
            'source_journal_entry_id' => $sourceApJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => $cashierSessionId,
            'cashier_payment_instrument_id' => $bankInstrumentId,
            'operational_gl_account_id' => $this->bankAccountId,
            'controlled_bank_account_id' => $controlledBankAccountId,
            'controlled_bank_statement_line_id' => $statementLineId,
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'executed_by' => $this->actor->id,
            'executed_at' => $timestamp,
            'source_snapshot' => json_encode(['controlled_bank_statement_line_id' => $statementLineId]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $paymentCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Posted BANK supplier payment candidate',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'manual_bank_reconciliation']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $postedPaymentJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'BANK-PAY-POST-' . $suffix,
            'description' => 'Posted BANK supplier payment fixture',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'GeneralCashier',
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'journal_candidate_id' => $paymentCandidateId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'posted_by' => null,
            'posted_at' => null,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $postedPaymentJournalEntryId,
                'account_id' => $this->apAccountId,
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'memo' => 'Debit AP liability control',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $postedPaymentJournalEntryId,
                'account_id' => $this->bankAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $amount,
                'memo' => 'Credit bank control account',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $postedPaymentJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'posted_by' => $this->actor->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->actor->id,
                'updated_at' => $timestamp,
            ]);

        return [
            'controlled_bank_account_id' => $controlledBankAccountId,
            'statement_line_id' => $statementLineId,
            'payment_execution_id' => $paymentExecutionId,
            'posted_journal_entry_id' => $postedPaymentJournalEntryId,
        ];
    }

    private function makeControlledBankAccount(): string
    {
        $id = (string) Str::ulid();
        $timestamp = now();

        DB::table('controlled_bank_accounts')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $this->bankAccountId,
            'bank_name' => 'Manual Reconciliation Bank',
            'account_name' => 'Operating Account',
            'external_account_reference' => 'BANK-RECON-EXT-' . $this->sequence++,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'BANK-RECON-SOURCE-' . $this->sequence++,
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'bank-recon-account-' . $id),
            'source_snapshot' => json_encode(['test_scope' => 'manual_bank_reconciliation']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $id;
    }

    private function makeStatementLine(string $bankAccountId, string $amount): string
    {
        $id = (string) Str::ulid();
        $timestamp = now();

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => $id,
            'controlled_bank_account_id' => $bankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'BANK-RECON-STMT-SOURCE-' . $this->sequence++,
            'external_reference' => 'BANK-RECON-STMT-' . $this->sequence++,
            'statement_date' => '2026-07-01',
            'direction' => ControlledBankStatementLineDirectionEnum::OUTFLOW->value,
            'amount' => $amount,
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'bank-recon-line-' . $id),
            'source_snapshot' => json_encode(['test_scope' => 'manual_bank_reconciliation']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $id;
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'bank_payment_reconciliations',
            'payment_executions',
            'controlled_bank_statement_lines',
            'controlled_bank_accounts',
            'gl_journal_entries',
            'gl_journal_entry_lines',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        return $snapshot;
    }

    private function assertControlledSnapshotUnchangedExcept(array $before, array $allowedDeltas): void
    {
        $after = $this->controlledSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count + ($allowedDeltas[$table] ?? 0), $after[$table], $table);
        }
    }

    private function makeAccount(string $code, string $type, string $category, string $normalBalance, bool $cashEquivalent): string
    {
        $accountId = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_accounts')->insert([
            'id' => $accountId,
            'property_id' => $this->property->id,
            'code' => $code,
            'name' => $code . ' Account',
            'normal_balance' => $normalBalance,
            'account_type' => $type,
            'account_category' => $category,
            'is_active' => true,
            'is_cash_equivalent' => $cashEquivalent,
            'created_by' => $this->actor?->id,
            'updated_by' => $this->actor?->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $accountId;
    }

    private function attachActorToProperty(User $actor, Property $property): void
    {
        $actor->properties()->syncWithoutDetaching([
            $property->id => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);
    }

    private function makeProperty(): Property
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $timestamp = now();
        $suffix = $this->sequence++;

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'Manual Bank Reconciliation Company ' . $suffix,
            'slug' => 'manual-bank-reconciliation-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Manual Bank Reconciliation Property ' . $suffix,
            'slug' => 'manual-bank-reconciliation-property-' . $suffix,
            'code' => 'BR' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Property::query()->findOrFail($propertyId);
    }

    private function makeUser(): User
    {
        $userId = (string) Str::ulid();
        $suffix = $this->sequence++;
        $timestamp = now();

        DB::table('users')->insert([
            'id' => $userId,
            'is_system_admin' => false,
            'name' => 'Manual Bank Reconciliation User ' . $suffix,
            'email' => 'manual-bank-reconciliation-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }
}
