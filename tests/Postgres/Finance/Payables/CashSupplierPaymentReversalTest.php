<?php

namespace Tests\Postgres\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Models\Account;
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
use Modules\Operations\GeneralCashier\Services\CashReturnEvidenceService;
use Modules\Operations\GeneralCashier\Services\CashSupplierPaymentReversalService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class CashSupplierPaymentReversalTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private string $apAccountId;
    private string $cashAccountId;
    private CashReturnEvidenceService $returnService;
    private CashSupplierPaymentReversalService $reversalService;
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
        $this->actingAs($this->actor);

        $this->apAccountId = $this->makeAccount('AP-REV-' . $this->sequence++, 'Liability', 'Credit', false);
        $this->cashAccountId = $this->makeAccount('CASH-REV-' . $this->sequence++, 'Asset', 'Debit', true);
        $this->makeOperationalIdentityMapping(OperationalIdentityEnum::AP_CONTROL, $this->apAccountId);
        $this->makeOperationalIdentityMapping(OperationalIdentityEnum::CASH_AND_BANK, $this->cashAccountId);
        $this->makeOpenPostingBoundaries();

        foreach ([
            CashReturnEvidenceService::PERMISSION,
            CashSupplierPaymentReversalService::PERMISSION,
            SupplierPaymentJournalCandidateService::PERMISSION,
            JournalCandidateReviewService::PERMISSION,
            JournalCandidateDraftMaterializationService::PERMISSION,
            JournalEntryDraftFinalizationAuthorizationService::PERMISSION,
            JournalEntryControlledPostingService::PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo([
            CashReturnEvidenceService::PERMISSION,
            CashSupplierPaymentReversalService::PERMISSION,
            SupplierPaymentJournalCandidateService::PERMISSION,
            JournalCandidateReviewService::PERMISSION,
            JournalCandidateDraftMaterializationService::PERMISSION,
            JournalEntryDraftFinalizationAuthorizationService::PERMISSION,
            JournalEntryControlledPostingService::PERMISSION,
        ]);

        $this->returnService = app(CashReturnEvidenceService::class);
        $this->reversalService = app(CashSupplierPaymentReversalService::class);
        $this->candidateService = app(SupplierPaymentJournalCandidateService::class);
        $this->reviewService = app(JournalCandidateReviewService::class);
        $this->draftService = app(JournalCandidateDraftMaterializationService::class);
        $this->authorizationService = app(JournalEntryDraftFinalizationAuthorizationService::class);
        $this->postingService = app(JournalEntryControlledPostingService::class);
    }

    public function test_cash_supplier_payment_reversal_uses_return_evidence_and_controlled_posting(): void
    {
        $payment = $this->makePostedCashSupplierPayment('1000.00');
        $return = $this->returnService->recordCashReturn(
            $payment['posted_journal_entry_id'],
            'REVERSAL-CASH-RETURN-001',
            '2026-07-01',
            $this->actor
        );
        $before = $this->controlledSnapshot();

        $reversal = $this->reversalService->recordReversalExecution($return->id, $this->actor);
        $this->assertSame($return->id, $reversal->cash_return_evidence_id);
        $this->assertSame($payment['payment_execution_id'], $reversal->original_payment_execution_id);
        $this->assertSame($payment['posted_journal_entry_id'], $reversal->original_posted_journal_entry_id);
        $this->assertSame('1000.00', (string) $reversal->reversal_amount);

        $candidate = $this->candidateService->createForCashReversalExecution($reversal->id);
        $this->assertSame('CashSupplierPaymentReversalExecution', $candidate->source_type);
        $this->assertSame($reversal->id, $candidate->source_id);
        $this->assertSame(SupplierPaymentJournalCandidateService::REVERSAL_POSTING_EVENT, $candidate->posting_event);

        $candidateLines = $candidate->lines()->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame('CASH_AND_BANK', $candidateLines[0]->operational_identity->value);
        $this->assertSame('DEBIT', $candidateLines[0]->entry_type->value);
        $this->assertSame('AP_CONTROL', $candidateLines[1]->operational_identity->value);
        $this->assertSame('CREDIT', $candidateLines[1]->entry_type->value);

        $this->reviewService->approve($candidate->id, $this->actor->id);
        $draft = $this->draftService->materialize($candidate->id, $this->actor->id);
        $this->authorizationService->authorize($draft->id, $this->actor->id);
        $posted = $this->postingService->post($draft->id, $this->actor->id);

        $this->assertSame(JournalStatusEnum::Posted, $posted->status);
        $this->assertSame('GeneralCashier', $posted->source_module);
        $this->assertSame('CashSupplierPaymentReversalExecution', $posted->source_type);
        $this->assertSame($reversal->id, $posted->source_id);
        $this->assertSame(SupplierPaymentJournalCandidateService::REVERSAL_POSTING_EVENT, $posted->posting_event);
        $this->assertSame($this->actor->id, $posted->posted_by);

        $postedLines = $posted->lines()->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame($this->cashAccountId, $postedLines[0]->account_id);
        $this->assertSame('1000.00', (string) $postedLines[0]->debit_amount);
        $this->assertSame('0.00', (string) $postedLines[0]->credit_amount);
        $this->assertSame($this->apAccountId, $postedLines[1]->account_id);
        $this->assertSame('0.00', (string) $postedLines[1]->debit_amount);
        $this->assertSame('1000.00', (string) $postedLines[1]->credit_amount);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'cash_supplier_payment_reversal_executions' => 1,
            'journal_candidates' => 1,
            'journal_candidate_lines' => 2,
            'gl_journal_entries' => 1,
            'gl_journal_entry_lines' => 2,
            'gl_ledger_balances' => 2,
        ]);

        $replay = $this->reversalService->recordReversalExecution($return->id, $this->actor);
        $this->assertSame($reversal->id, $replay->id);
    }

    /**
     * @return array{payment_execution_id: string, posted_journal_entry_id: string, vendor_id: string}
     */
    private function makePostedCashSupplierPayment(string $amount): array
    {
        $timestamp = now();
        $suffix = $this->sequence++;
        $vendorId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $sourceApJournalEntryId = (string) Str::ulid();
        $sourceApCandidateId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $paymentExecutionId = (string) Str::ulid();
        $instrumentId = (string) Str::ulid();
        $paymentCandidateId = (string) Str::ulid();
        $paymentJournalEntryId = (string) Str::ulid();

        DB::table('cashier_payment_instruments')->insert([
            'id' => $instrumentId,
            'property_id' => $this->property->id,
            'name' => 'Cash Reversal Instrument ' . $suffix,
            'type' => CashierPaymentInstrumentTypeEnum::CASH->value,
            'operational_gl_account_id' => $this->cashAccountId,
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'CASH-REV-PROP-' . $suffix,
            'currency_code' => 'IDR',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', 'cash-reversal-proposal-' . $suffix),
            'total_amount' => $amount,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => $itemId,
            'payment_proposal_id' => $proposalId,
            'property_id' => $this->property->id,
            'source_journal_entry_id' => $sourceApJournalEntryId,
            'source_journal_candidate_id' => $sourceApCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'cash_supplier_payment_reversal']),
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
            'payment_proposal_item_id' => $itemId,
            'source_journal_entry_id' => $sourceApJournalEntryId,
            'source_journal_candidate_id' => $sourceApCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => (string) Str::ulid(),
            'cashier_payment_instrument_id' => $instrumentId,
            'operational_gl_account_id' => $this->cashAccountId,
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'executed_by' => $this->actor->id,
            'executed_at' => $timestamp,
            'source_snapshot' => json_encode(['test_scope' => 'cash_supplier_payment_reversal']),
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
            'posting_event' => SupplierPaymentJournalCandidateService::POSTING_EVENT,
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Original posted cash supplier payment candidate fixture',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'cash_supplier_payment_reversal']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $paymentJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'CASH-REV-JOURNAL-' . $suffix,
            'description' => 'Original posted cash supplier payment fixture',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'GeneralCashier',
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'journal_candidate_id' => $paymentCandidateId,
            'posting_event' => SupplierPaymentJournalCandidateService::POSTING_EVENT,
            'draft_finalization_authorized_by' => $this->actor->id,
            'draft_finalization_authorized_at' => $timestamp,
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
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $this->apAccountId,
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'memo' => 'Original debit AP liability fixture',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $this->cashAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $amount,
                'memo' => 'Original credit cash control fixture',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $paymentJournalEntryId)
            ->update([
                'status' => JournalStatusEnum::Posted->value,
                'posting_date' => '2026-07-01',
                'posted_by' => $this->actor->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->actor->id,
                'updated_at' => $timestamp,
            ]);

        return [
            'payment_execution_id' => $paymentExecutionId,
            'posted_journal_entry_id' => $paymentJournalEntryId,
            'vendor_id' => $vendorId,
        ];
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'cash_return_evidence',
            'cash_supplier_payment_reversal_executions',
            'payment_executions',
            'cashbook_transactions',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'payment_proposals',
            'payment_proposal_items',
            'property_business_dates',
            'gl_financial_periods',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::getSchemaBuilder()->hasTable($table)
                ? DB::table($table)->count()
                : 0;
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

    private function makeOperationalIdentityMapping(OperationalIdentityEnum $identity, string $accountId): void
    {
        $timestamp = now();

        DB::table('gl_operational_identity_mappings')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'operational_identity' => $identity->value,
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

    private function makeAccount(string $code, string $type, string $normalBalance, bool $cashEquivalent): string
    {
        $account = Account::create([
            'property_id' => $this->property->id,
            'code' => $code,
            'name' => $code . ' Account',
            'normal_balance' => $normalBalance,
            'account_type' => $type,
            'account_category' => $type === 'Asset' ? 'CurrentAsset' : 'CurrentLiability',
            'is_active' => true,
            'is_cash_equivalent' => $cashEquivalent,
            'created_by' => $this->actor?->id,
            'updated_by' => $this->actor?->id,
        ]);

        return $account->id;
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
            'name' => 'Cash Reversal Company ' . $suffix,
            'slug' => 'cash-reversal-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Cash Reversal Property ' . $suffix,
            'slug' => 'cash-reversal-property-' . $suffix,
            'code' => 'RV' . $suffix,
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
            'name' => 'Cash Reversal User ' . $suffix,
            'email' => 'cash-reversal-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }
}
