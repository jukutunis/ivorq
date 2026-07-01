<?php

namespace Tests\Postgres\Finance\Payables;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Services\JournalCandidateDraftMaterializationService;
use Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService;
use Modules\Finance\GeneralLedger\Services\JournalEntryControlledPostingService;
use Modules\Finance\GeneralLedger\Services\JournalEntryDraftFinalizationAuthorizationService;
use Modules\Finance\GeneralLedger\Services\SupplierPaymentJournalCandidateService;
use Modules\Finance\Payables\Services\ApOutstandingProjectionService;
use Modules\Finance\Payables\Services\ApSettlementAllocationService;
use Modules\Finance\Payables\Services\PaymentProposalApprovalService;
use Modules\Finance\Payables\Services\PaymentProposalService;
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

class PartialAndSplitSupplierPaymentTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private User $approver;
    private string $apAccountId;
    private string $cashAccountId;
    private PaymentProposalService $proposalService;
    private PaymentProposalApprovalService $proposalApprovalService;
    private GeneralCashierOperationalFoundationService $cashierService;
    private PaymentExecutionService $paymentExecutionService;
    private SupplierPaymentJournalCandidateService $candidateService;
    private JournalCandidateReviewService $reviewService;
    private JournalCandidateDraftMaterializationService $draftService;
    private JournalEntryDraftFinalizationAuthorizationService $authorizationService;
    private JournalEntryControlledPostingService $postingService;
    private ApSettlementAllocationService $allocationService;
    private ApOutstandingProjectionService $outstandingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->approver = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);
        $this->attachActorToProperty($this->approver, $this->property);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->actingAs($this->actor);

        $this->apAccountId = $this->makeAccount('AP-PARTIAL-' . $this->sequence++, 'Liability', 'CurrentLiability', 'Credit', false);
        $this->cashAccountId = $this->makeAccount('CASH-PARTIAL-' . $this->sequence++, 'Asset', 'CurrentAsset', 'Debit', true);
        $this->makeOperationalIdentityMapping('AP_CONTROL', $this->apAccountId);
        $this->makeOperationalIdentityMapping('CASH_AND_BANK', $this->cashAccountId);
        $this->makeOpenPostingBoundaries();

        foreach ($this->permissions() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo($this->permissions());
        $this->approver->givePermissionTo($this->permissions());

        $this->proposalService = app(PaymentProposalService::class);
        $this->proposalApprovalService = app(PaymentProposalApprovalService::class);
        $this->cashierService = app(GeneralCashierOperationalFoundationService::class);
        $this->paymentExecutionService = app(PaymentExecutionService::class);
        $this->candidateService = app(SupplierPaymentJournalCandidateService::class);
        $this->reviewService = app(JournalCandidateReviewService::class);
        $this->draftService = app(JournalCandidateDraftMaterializationService::class);
        $this->authorizationService = app(JournalEntryDraftFinalizationAuthorizationService::class);
        $this->postingService = app(JournalEntryControlledPostingService::class);
        $this->allocationService = app(ApSettlementAllocationService::class);
        $this->outstandingService = app(ApOutstandingProjectionService::class);
    }

    public function test_partial_payment_posts_allocates_and_enables_next_sequential_intent(): void
    {
        $source = $this->makePostedApLiability('300.00');

        $proposal = $this->proposalService->createSequentialPartialDraft($source['journal_entry_id'], '125.00', $this->actor);
        $item = $proposal->items->first();

        $this->assertSame('300.00', (string) $item->source_amount);
        $this->assertSame('300.00', (string) $item->original_source_amount);
        $this->assertSame('125.00', (string) $item->requested_payment_amount);
        $this->assertSame('125.00', (string) $proposal->total_amount);

        $replay = $this->proposalService->createSequentialPartialDraft($source['journal_entry_id'], '125.00', $this->actor);
        $this->assertSame($proposal->id, $replay->id);

        try {
            $this->proposalService->createSequentialPartialDraft($source['journal_entry_id'], '175.00', $this->actor);
            $this->fail('A second active partial payment intent must fail before allocation.');
        } catch (DomainException) {
            $this->assertSame(1, DB::table('payment_proposal_items')->where('source_journal_entry_id', $source['journal_entry_id'])->count());
        }

        $approved = $this->approveProposal($proposal->id);
        $cashier = $this->makeCashierContext();
        $before = $this->controlledSnapshot();

        $execution = $this->paymentExecutionService->recordCashExecution(
            $approved->items->first()->id,
            $cashier['session_id'],
            $cashier['instrument_id'],
            $this->actor
        );

        $this->assertSame('125.00', (string) $execution->source_amount);
        $this->assertNotNull($execution->payment_intent_key);

        $candidate = $this->candidateService->createForPaymentExecution($execution->id);
        $this->reviewService->approve($candidate->id, $this->actor->id);
        $draft = $this->draftService->materialize($candidate->id, $this->actor->id);
        $this->authorizationService->authorize($draft->id, $this->actor->id);
        $posted = $this->postingService->post($draft->id, $this->actor->id);
        $allocation = $this->allocationService->allocate($source['journal_entry_id'], $posted->id, '125.00', $this->actor);

        $this->assertSame(JournalStatusEnum::Posted, $posted->status);
        $postedLines = $posted->lines()->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame($this->apAccountId, $postedLines[0]->account_id);
        $this->assertSame('125.00', (string) $postedLines[0]->debit_amount);
        $this->assertSame($this->cashAccountId, $postedLines[1]->account_id);
        $this->assertSame('125.00', (string) $postedLines[1]->credit_amount);
        $this->assertSame($execution->id, $allocation->payment_execution_id);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'payment_executions' => 1,
            'journal_candidates' => 1,
            'journal_candidate_lines' => 2,
            'gl_journal_entries' => 1,
            'gl_journal_entry_lines' => 2,
            'gl_ledger_balances' => 2,
            'cashbook_transactions' => 1,
            'ap_settlement_allocations' => 1,
        ]);

        $this->assertFalse((bool) DB::table('payment_proposal_items')->where('id', $item->id)->value('is_active'));
        $this->assertSame('175.00', $this->outstandingService->outstandingForPostedApJournal($source['journal']));

        $next = $this->proposalService->createSequentialPartialDraft($source['journal_entry_id'], '175.00', $this->actor);
        $this->assertNotSame($proposal->id, $next->id);
        $this->assertSame('175.00', (string) $next->items->first()->requested_payment_amount);
    }

    public function test_partial_payment_fails_closed_for_over_request_and_reversal_linked_payment(): void
    {
        $source = $this->makePostedApLiability('100.00');

        try {
            $this->proposalService->createSequentialPartialDraft($source['journal_entry_id'], '100.01', $this->actor);
            $this->fail('Requested amount above derived outstanding must fail closed.');
        } catch (DomainException) {
            $this->assertSame(0, DB::table('payment_proposals')->count());
        }

        $execution = $this->postPartialPayment($source, '60.00');
        DB::table('cash_supplier_payment_reversal_executions')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'vendor_id' => $source['vendor_id'],
            'cash_return_evidence_id' => (string) Str::ulid(),
            'original_payment_execution_id' => $execution['execution_id'],
            'original_posted_journal_entry_id' => $execution['posted_journal_entry_id'],
            'operational_gl_account_id' => $this->cashAccountId,
            'currency_code' => 'IDR',
            'reversal_amount' => '60.00',
            'reversed_by' => $this->actor->id,
            'reversed_at' => now(),
            'source_identity_hash' => hash('sha256', 'reversal-linked-test-' . $this->sequence++),
            'source_snapshot' => json_encode(['test_scope' => 'partial_payment_reversal_link']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->allocationService->allocate($source['journal_entry_id'], $execution['posted_journal_entry_id'], '60.00', $this->actor);
            $this->fail('Reversal-linked supplier payment evidence must not allocate in the initial partial scope.');
        } catch (DomainException) {
            $this->assertSame(0, DB::table('ap_settlement_allocations')->count());
        }
    }

    public function test_historical_full_obligation_payment_behavior_remains_valid(): void
    {
        $source = $this->makePostedApLiability('80.00');
        $proposal = $this->proposalService->createDraft([$source['journal_entry_id']], $this->actor);
        $approved = $this->approveProposal($proposal->id);
        $cashier = $this->makeCashierContext();

        $execution = $this->paymentExecutionService->recordCashExecution(
            $approved->items->first()->id,
            $cashier['session_id'],
            $cashier['instrument_id'],
            $this->actor
        );

        $this->assertNull($execution->payment_intent_key);
        $this->assertSame('80.00', (string) $execution->source_amount);

        $candidate = $this->candidateService->createForPaymentExecution($execution->id);
        $lines = $candidate->lines->sortBy('created_at')->values();
        $this->assertSame('80.0000', (string) $lines[0]->amount);
        $this->assertSame('80.0000', (string) $lines[1]->amount);
    }

    private function postPartialPayment(array $source, string $amount): array
    {
        $proposal = $this->proposalService->createSequentialPartialDraft($source['journal_entry_id'], $amount, $this->actor);
        $approved = $this->approveProposal($proposal->id);
        $cashier = $this->makeCashierContext();
        $execution = $this->paymentExecutionService->recordCashExecution(
            $approved->items->first()->id,
            $cashier['session_id'],
            $cashier['instrument_id'],
            $this->actor
        );
        $candidate = $this->candidateService->createForPaymentExecution($execution->id);
        $this->reviewService->approve($candidate->id, $this->actor->id);
        $draft = $this->draftService->materialize($candidate->id, $this->actor->id);
        $this->authorizationService->authorize($draft->id, $this->actor->id);
        $posted = $this->postingService->post($draft->id, $this->actor->id);

        return [
            'execution_id' => $execution->id,
            'posted_journal_entry_id' => $posted->id,
        ];
    }

    private function approveProposal(string $proposalId)
    {
        $this->proposalApprovalService->submit($proposalId, $this->actor);

        return $this->proposalApprovalService->approve($proposalId, $this->approver);
    }

    private function makePostedApLiability(string $amount): array
    {
        $timestamp = now();
        $vendorId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $sourceCandidateId = (string) Str::ulid();
        $sourceJournalEntryId = (string) Str::ulid();
        $grniCandidateId = (string) Str::ulid();
        $grniJournalEntryId = (string) Str::ulid();
        $suffix = $this->sequence++;

        DB::table('vendor_invoices')->insert([
            'id' => $supplierInvoiceId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'invoice_number' => 'PARTIAL-INV-' . $suffix,
            'invoice_date' => '2026-07-01',
            'currency_code' => 'IDR',
            'due_date' => '2026-07-15',
            'status' => 'APPROVED',
            'subtotal' => $amount,
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'grand_total' => $amount,
            'approved_by' => $this->approver->id,
            'approved_at' => $timestamp,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'source_grni_candidate_id' => $grniCandidateId,
            'source_grni_journal_entry_id' => $grniJournalEntryId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Posted AP liability candidate for partial payment',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'partial_supplier_payment']),
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
            'reference' => 'AP-PARTIAL-' . $suffix,
            'description' => 'Posted AP liability for partial supplier payment',
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
                'account_id' => $this->makeAccount('INV-PARTIAL-' . $this->sequence++, 'Asset', 'CurrentAsset', 'Debit', false),
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

        return [
            'vendor_id' => $vendorId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'journal_entry_id' => $sourceJournalEntryId,
            'journal' => JournalEntry::with('lines')->findOrFail($sourceJournalEntryId),
        ];
    }

    private function makeCashierContext(): array
    {
        $session = $this->cashierService->openSession($this->actor);
        $instrumentId = (string) Str::ulid();
        $timestamp = now();

        DB::table('cashier_payment_instruments')->insert([
            'id' => $instrumentId,
            'property_id' => $this->property->id,
            'name' => 'Partial CASH Instrument ' . $this->sequence++,
            'type' => CashierPaymentInstrumentTypeEnum::CASH->value,
            'operational_gl_account_id' => $this->cashAccountId,
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
            'ap_settlement_allocations',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'payment_proposals',
            'payment_proposal_items',
            'cashbook_transactions',
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
            PaymentProposalService::CREATE_PERMISSION,
            PaymentProposalApprovalService::SUBMIT_PERMISSION,
            PaymentProposalApprovalService::APPROVE_PERMISSION,
            GeneralCashierOperationalFoundationService::OPEN_PERMISSION,
            PaymentExecutionService::PERMISSION,
            SupplierPaymentJournalCandidateService::PERMISSION,
            JournalCandidateReviewService::PERMISSION,
            JournalCandidateDraftMaterializationService::PERMISSION,
            JournalEntryDraftFinalizationAuthorizationService::PERMISSION,
            JournalEntryControlledPostingService::PERMISSION,
            ApSettlementAllocationService::PERMISSION,
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
            'name' => 'Partial Supplier Payment Company ' . $suffix,
            'slug' => 'partial-supplier-payment-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Partial Supplier Payment Property ' . $suffix,
            'slug' => 'partial-supplier-payment-property-' . $suffix,
            'code' => 'PSP' . $suffix,
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
            'name' => 'Partial Supplier Payment User ' . $suffix,
            'email' => 'partial-supplier-payment-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }
}
