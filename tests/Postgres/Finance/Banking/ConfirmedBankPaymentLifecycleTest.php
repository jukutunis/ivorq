<?php

namespace Tests\Postgres\Finance\Banking;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Services\BankingSourceEvidenceService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Services\JournalCandidateDraftMaterializationService;
use Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService;
use Modules\Finance\GeneralLedger\Services\JournalEntryControlledPostingService;
use Modules\Finance\GeneralLedger\Services\JournalEntryDraftFinalizationAuthorizationService;
use Modules\Finance\GeneralLedger\Services\SupplierPaymentJournalCandidateService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Services\GeneralCashierOperationalFoundationService;
use Modules\Operations\GeneralCashier\Services\PaymentExecutionService;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class ConfirmedBankPaymentLifecycleTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private string $apAccountId;
    private string $bankAccountId;
    private BankingSourceEvidenceService $bankingService;
    private GeneralCashierOperationalFoundationService $cashierService;
    private PaymentExecutionService $paymentExecutionService;
    private SupplierPaymentJournalCandidateService $candidateService;
    private JournalCandidateReviewService $reviewService;
    private JournalCandidateDraftMaterializationService $draftService;
    private JournalEntryDraftFinalizationAuthorizationService $authorizationService;
    private JournalEntryControlledPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->actingAs($this->actor);

        $this->apAccountId = $this->makeAccount('AP-BANK-' . $this->sequence++, 'Liability', 'CurrentLiability', 'Credit', false);
        $this->bankAccountId = $this->makeAccount('BANK-' . $this->sequence++, 'Asset', 'CurrentAsset', 'Debit', true);
        $this->makeOperationalIdentityMapping('AP_CONTROL', $this->apAccountId);
        $this->makeOperationalIdentityMapping('CASH_AND_BANK', $this->bankAccountId);
        $this->makeOpenPostingBoundaries();

        foreach ($this->permissions() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo($this->permissions());

        $this->bankingService = app(BankingSourceEvidenceService::class);
        $this->cashierService = app(GeneralCashierOperationalFoundationService::class);
        $this->paymentExecutionService = app(PaymentExecutionService::class);
        $this->candidateService = app(SupplierPaymentJournalCandidateService::class);
        $this->reviewService = app(JournalCandidateReviewService::class);
        $this->draftService = app(JournalCandidateDraftMaterializationService::class);
        $this->authorizationService = app(JournalEntryDraftFinalizationAuthorizationService::class);
        $this->postingService = app(JournalEntryControlledPostingService::class);
    }

    public function test_confirmed_bank_payment_uses_independent_statement_line_and_controlled_posting(): void
    {
        $source = $this->makeApprovedPaymentProposalItem('125.00');
        $bank = $this->bankingService->registerBankAccount(
            $this->bankAccountId,
            'Controlled Bank',
            'Operating Account',
            'BANK-EXT-' . $this->sequence++,
            'IDR',
            'BANK-ACCOUNT-SOURCE-' . $this->sequence++,
            $this->actor
        );
        $statement = $this->bankingService->registerStatementLine(
            $bank->id,
            'STATEMENT-SOURCE-' . $this->sequence++,
            'BANK-LINE-' . $this->sequence++,
            '2026-07-01',
            ControlledBankStatementLineDirectionEnum::OUTFLOW,
            '125.00',
            'IDR',
            $this->actor,
            $source['vendor_id']
        );
        $cashier = $this->makeBankCashierContext();
        $before = $this->controlledSnapshot();

        $execution = $this->paymentExecutionService->recordConfirmedBankExecution(
            $source['proposal_item_id'],
            $cashier['session_id'],
            $cashier['instrument_id'],
            $bank->id,
            $statement->id,
            $this->actor
        );

        $this->assertSame($this->property->id, $execution->property_id);
        $this->assertSame($source['vendor_id'], $execution->vendor_id);
        $this->assertSame($source['proposal_item_id'], $execution->payment_proposal_item_id);
        $this->assertSame($source['posted_ap_journal_entry_id'], $execution->source_journal_entry_id);
        $this->assertSame($this->bankAccountId, $execution->operational_gl_account_id);
        $this->assertSame($bank->id, $execution->controlled_bank_account_id);
        $this->assertSame($statement->id, $execution->controlled_bank_statement_line_id);
        $this->assertSame('125.00', (string) $execution->source_amount);

        $candidate = $this->candidateService->createForPaymentExecution($execution->id);
        $this->reviewService->approve($candidate->id, $this->actor->id);
        $draft = $this->draftService->materialize($candidate->id, $this->actor->id);
        $this->authorizationService->authorize($draft->id, $this->actor->id);
        $posted = $this->postingService->post($draft->id, $this->actor->id);

        $this->assertSame(JournalStatusEnum::Posted, $posted->status);
        $this->assertSame('PaymentExecution', $posted->source_type);
        $this->assertSame($execution->id, $posted->source_id);
        $postedLines = $posted->lines()->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame($this->apAccountId, $postedLines[0]->account_id);
        $this->assertSame('125.00', (string) $postedLines[0]->debit_amount);
        $this->assertSame($this->bankAccountId, $postedLines[1]->account_id);
        $this->assertSame('125.00', (string) $postedLines[1]->credit_amount);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'payment_executions' => 1,
            'journal_candidates' => 1,
            'journal_candidate_lines' => 2,
            'gl_journal_entries' => 1,
            'gl_journal_entry_lines' => 2,
            'gl_ledger_balances' => 2,
        ]);

        $this->assertSame(0, DB::table('cashbook_transactions')->count());

        $replay = $this->paymentExecutionService->recordConfirmedBankExecution(
            $source['proposal_item_id'],
            $cashier['session_id'],
            $cashier['instrument_id'],
            $bank->id,
            $statement->id,
            $this->actor
        );
        $this->assertSame($execution->id, $replay->id);
    }

    public function test_confirmed_bank_payment_fails_closed_for_invalid_actor_and_statement_evidence(): void
    {
        $source = $this->makeApprovedPaymentProposalItem('125.00');
        $bank = $this->bankingService->registerBankAccount(
            $this->bankAccountId,
            'Controlled Bank',
            'Operating Account',
            'BANK-EXT-' . $this->sequence++,
            'IDR',
            'BANK-ACCOUNT-SOURCE-' . $this->sequence++,
            $this->actor
        );
        $statement = $this->bankingService->registerStatementLine(
            $bank->id,
            'STATEMENT-SOURCE-' . $this->sequence++,
            'BANK-LINE-' . $this->sequence++,
            '2026-07-01',
            ControlledBankStatementLineDirectionEnum::OUTFLOW,
            '125.00',
            'IDR',
            $this->actor,
            $source['vendor_id']
        );
        $cashier = $this->makeBankCashierContext();
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);

        try {
            $this->paymentExecutionService->recordConfirmedBankExecution(
                $source['proposal_item_id'],
                $cashier['session_id'],
                $cashier['instrument_id'],
                $bank->id,
                $statement->id,
                $unauthorized
            );
            $this->fail('Unauthorized confirmed BANK execution must fail closed.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('payment_executions')->count());
        }

        $conflicting = $this->bankingService->registerStatementLine(
            $bank->id,
            'STATEMENT-SOURCE-' . $this->sequence++,
            'BANK-LINE-' . $this->sequence++,
            '2026-07-01',
            ControlledBankStatementLineDirectionEnum::OUTFLOW,
            '126.00',
            'IDR',
            $this->actor,
            $source['vendor_id']
        );

        try {
            $this->paymentExecutionService->recordConfirmedBankExecution(
                $source['proposal_item_id'],
                $cashier['session_id'],
                $cashier['instrument_id'],
                $bank->id,
                $conflicting->id,
                $this->actor
            );
            $this->fail('Conflicting bank statement amount must fail closed.');
        } catch (DomainException) {
            $this->assertSame(0, DB::table('payment_executions')->count());
        }
    }

    private function makeApprovedPaymentProposalItem(string $amount): array
    {
        $timestamp = now();
        $vendorId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $sourceCandidateId = (string) Str::ulid();
        $sourceJournalEntryId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $proposalItemId = (string) Str::ulid();
        $suffix = $this->sequence++;

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Posted AP liability candidate for confirmed BANK payment',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'confirmed_bank_payment']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $sourceJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'AP-BANK-SOURCE-' . $suffix,
            'description' => 'Posted AP liability source for confirmed BANK payment',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'Payables',
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'journal_candidate_id' => $sourceCandidateId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
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
                'journal_entry_id' => $sourceJournalEntryId,
                'account_id' => $this->makeAccount('INV-BANK-' . $this->sequence++, 'Asset', 'CurrentAsset', 'Debit', false),
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'memo' => 'Debit source inventory fixture',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $sourceJournalEntryId,
                'account_id' => $this->apAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $amount,
                'memo' => 'Credit AP liability fixture',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $sourceJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'posted_by' => $this->actor->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->actor->id,
                'updated_at' => $timestamp,
            ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'BANK-PAY-' . $suffix,
            'currency_code' => 'IDR',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', $sourceJournalEntryId),
            'total_amount' => $amount,
            'submitted_by' => $this->actor->id,
            'submitted_at' => $timestamp,
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => $proposalItemId,
            'payment_proposal_id' => $proposalId,
            'property_id' => $this->property->id,
            'source_journal_entry_id' => $sourceJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'is_active' => true,
            'source_snapshot' => json_encode(['posted_ap_journal_entry_id' => $sourceJournalEntryId]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'vendor_id' => $vendorId,
            'proposal_item_id' => $proposalItemId,
            'posted_ap_journal_entry_id' => $sourceJournalEntryId,
        ];
    }

    private function makeBankCashierContext(): array
    {
        $session = $this->cashierService->openSession($this->actor);
        $instrumentId = (string) Str::ulid();
        $timestamp = now();

        DB::table('cashier_payment_instruments')->insert([
            'id' => $instrumentId,
            'property_id' => $this->property->id,
            'name' => 'BANK Instrument ' . $this->sequence++,
            'type' => CashierPaymentInstrumentTypeEnum::BANK->value,
            'operational_gl_account_id' => $this->bankAccountId,
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'session_id' => $session->id,
            'instrument_id' => $instrumentId,
        ];
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'payment_executions',
            'controlled_bank_accounts',
            'controlled_bank_statement_lines',
            'cashbook_transactions',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'payment_proposals',
            'payment_proposal_items',
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

    private function permissions(): array
    {
        return [
            BankingSourceEvidenceService::REGISTER_ACCOUNT_PERMISSION,
            BankingSourceEvidenceService::REGISTER_STATEMENT_LINE_PERMISSION,
            GeneralCashierOperationalFoundationService::OPEN_PERMISSION,
            PaymentExecutionService::PERMISSION,
            SupplierPaymentJournalCandidateService::PERMISSION,
            JournalCandidateReviewService::PERMISSION,
            JournalCandidateDraftMaterializationService::PERMISSION,
            JournalEntryDraftFinalizationAuthorizationService::PERMISSION,
            JournalEntryControlledPostingService::PERMISSION,
        ];
    }

    private function makeOperationalIdentityMapping(string $identity, string $accountId): void
    {
        $timestamp = now();

        DB::table('gl_operational_identity_mappings')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'operational_identity' => $identity,
            'cost_center_id' => null,
            'account_id' => $accountId,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function makeOpenPostingBoundaries(): void
    {
        $timestamp = now();

        DB::table('property_business_dates')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'business_date' => '2026-07-01',
            'status' => PropertyBusinessDateStatusEnum::Open->value,
            'is_open' => true,
            'opened_by' => $this->actor->id,
            'opened_at' => $timestamp,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_financial_periods')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'period_year' => 2026,
            'period_month' => 7,
            'status' => FinancialPeriodStatusEnum::Open->value,
            'opened_at' => $timestamp,
            'opened_by' => $this->actor->id,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
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
            'name' => 'Confirmed Bank Payment Company ' . $suffix,
            'slug' => 'confirmed-bank-payment-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Confirmed Bank Payment Property ' . $suffix,
            'slug' => 'confirmed-bank-payment-property-' . $suffix,
            'code' => 'BP' . $suffix,
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
            'name' => 'Confirmed Bank Payment User ' . $suffix,
            'email' => 'confirmed-bank-payment-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }
}
