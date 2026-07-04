<?php

namespace Tests\Postgres\Finance\FxReference;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\FxReference\Enums\ExchangeRateEvidenceStatusEnum;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentCandidateService;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class RealizedFxAdjustmentCandidateFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private RealizedFxAdjustmentCandidateService $service;

    private string $apAccountId;
    private string $cashAccountId;
    private string $fxGainAccountId;
    private string $fxLossAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);

        $this->apAccountId = $this->makeAccount('AP-CTRL', 'Liability', 'CurrentLiability', 'Credit');
        $this->cashAccountId = $this->makeAccount('CASH-BANK', 'Asset', 'CurrentAsset', 'Debit', true);
        $this->fxGainAccountId = $this->makeAccount('FX-GAIN', 'Revenue', 'Revenue', 'Credit');
        $this->fxLossAccountId = $this->makeAccount('FX-LOSS', 'Expense', 'Expense', 'Debit');

        $this->makeMapping($this->property, OperationalIdentityEnum::AP_CONTROL, $this->apAccountId);
        $this->makeMapping($this->property, OperationalIdentityEnum::CASH_AND_BANK, $this->cashAccountId);
        $this->makeMapping($this->property, OperationalIdentityEnum::FX_GAIN, $this->fxGainAccountId);
        $this->makeMapping($this->property, OperationalIdentityEnum::FX_LOSS, $this->fxLossAccountId);

        Permission::firstOrCreate([
            'name' => RealizedFxAdjustmentCandidateService::PERMISSION,
            'guard_name' => 'web',
        ]);
        Permission::firstOrCreate([
            'name' => 'finance.payables.ap-settlement.allocate',
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo([
            RealizedFxAdjustmentCandidateService::PERMISSION,
            'finance.payables.ap-settlement.allocate'
        ]);

        $this->service = app(RealizedFxAdjustmentCandidateService::class);
    }

    private function assertNoExternalMutations(callable $callback): mixed
    {
        $tables = [
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'property_business_dates',
            'gl_financial_periods',
            'exchange_rate_evidences',
            'payment_adjustment_configuration_evidences'
        ];

        $countsBefore = [];
        foreach ($tables as $table) {
            $countsBefore[$table] = DB::table($table)->count();
        }

        $result = $callback();

        foreach ($tables as $table) {
            $this->assertSame(
                $countsBefore[$table],
                DB::table($table)->count(),
                "Table {$table} count was mutated."
            );
        }

        return $result;
    }

    public function test_happy_path_gain_creates_pending_review_candidate(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $candidate = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->create($context['allocation_id'], $this->actor);
        });

        $this->assertInstanceOf(JournalCandidate::class, $candidate);
        $this->assertSame(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
        $this->assertSame('SupplierPaymentRealizedForeignExchange', $candidate->posting_event);
        $this->assertSame($this->property->id, $candidate->property_id);
        $this->assertSame('ApSettlementAllocation', $candidate->source_type);
        $this->assertSame($context['allocation_id'], $candidate->source_id);

        $lines = $candidate->lines()->orderBy('id')->get();
        $this->assertCount(2, $lines);

        $this->assertSame(OperationalIdentityEnum::AP_CONTROL, $lines[0]->operational_identity);
        $this->assertSame(EntryTypeEnum::DEBIT, $lines[0]->entry_type);
        $this->assertSame('5.0000', (string)$lines[0]->amount);

        $this->assertSame(OperationalIdentityEnum::FX_GAIN, $lines[1]->operational_identity);
        $this->assertSame(EntryTypeEnum::CREDIT, $lines[1]->entry_type);
        $this->assertSame('5.0000', (string)$lines[1]->amount);
    }

    public function test_happy_path_loss_creates_pending_review_candidate(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '130.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $candidate = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->create($context['allocation_id'], $this->actor);
        });

        $this->assertInstanceOf(JournalCandidate::class, $candidate);
        $this->assertSame(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);

        $lines = $candidate->lines()->orderBy('id')->get();
        $this->assertCount(2, $lines);

        $this->assertSame(OperationalIdentityEnum::FX_LOSS, $lines[0]->operational_identity);
        $this->assertSame(EntryTypeEnum::DEBIT, $lines[0]->entry_type);
        $this->assertSame('5.0000', (string)$lines[0]->amount);

        $this->assertSame(OperationalIdentityEnum::AP_CONTROL, $lines[1]->operational_identity);
        $this->assertSame(EntryTypeEnum::CREDIT, $lines[1]->entry_type);
        $this->assertSame('5.0000', (string)$lines[1]->amount);
    }

    public function test_zero_difference_returns_controlled_bypass(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '125.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->create($context['allocation_id'], $this->actor);
        });

        $this->assertIsArray($result);
        $this->assertSame('ZERO_REALIZED_FX_DIFFERENCE', $result['status']);
        $this->assertSame('0.00', $result['diff']);
        $this->assertDatabaseCount('journal_candidates', 2); // Two candidates created in setup, none added
    }

    public function test_same_currency_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'USD',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '100.00',
            settlementBasis: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Settlement allocation is not eligible');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_partial_settlement_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '40.00',
            carryingBasis: '125.00',
            settlementBasis: '50.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('only supports one-to-one full settlement');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_missing_ap_control_line_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED,
            excludeApControlLine: true
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Settlement allocation is not eligible for realized FX candidate creation: MISSING_BASIS');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_missing_payment_side_settlement_line_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED,
            excludeApControlLine: false,
            excludeCashLine: true
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Settlement allocation is not eligible for realized FX candidate creation: MISSING_BASIS');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_missing_posted_journal_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED,
            excludeApControlLine: false,
            excludeCashLine: false,
            excludeApJournal: true
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Settlement allocation is not eligible for realized FX candidate creation: MISSING_PROVENANCE');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_missing_rate_evidence_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::RECORDED
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Settlement allocation is not eligible');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_inactive_mapping_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        DB::table('gl_operational_identity_mappings')
            ->where('property_id', $this->property->id)
            ->where('operational_identity', OperationalIdentityEnum::FX_GAIN->value)
            ->update(['is_active' => false]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Settlement allocation is not eligible for realized FX candidate creation: INVALID_MAPPING');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_invalid_mapping_account_type_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        DB::table('gl_operational_identity_mappings')
            ->where('property_id', $this->property->id)
            ->where('operational_identity', OperationalIdentityEnum::FX_GAIN->value)
            ->update(['account_id' => $this->fxLossAccountId]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('INVALID_MAPPING');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_actor_permission_failures_fail_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $otherActor = $this->makeUser();
        $this->attachActorToProperty($otherActor, $this->property);

        $this->expectException(AuthorizationException::class);

        $this->service->create($context['allocation_id'], $otherActor);
    }

    public function test_identical_replay_is_idempotent(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $first = $this->service->create($context['allocation_id'], $this->actor);
        $second = $this->service->create($context['allocation_id'], $this->actor);

        $this->assertSame($first->id, $second->id);
    }

    public function test_conflicting_replay_fails_controlled(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $first = $this->service->create($context['allocation_id'], $this->actor);

        // Mutate existing candidate metadata to conflict
        $meta = $first->metadata;
        $meta['carrying_basis'] = '999.00';
        DB::table('journal_candidates')->where('id', $first->id)->update([
            'metadata' => json_encode($meta)
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Conflicting candidate replay for same allocation tuple.');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_advanced_candidate_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            carryingBasis: '125.00',
            settlementBasis: '120.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $first = $this->service->create($context['allocation_id'], $this->actor);

        DB::table('journal_candidates')->where('id', $first->id)->update([
            'status' => JournalCandidateStatusEnum::APPROVED->value
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Existing FX candidate is no longer PENDING_REVIEW');

        $this->service->create($context['allocation_id'], $this->actor);
    }

    public function test_caller_cannot_inject_values(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'create');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('allocationId', $parameters[0]->getName());
        $this->assertSame('actor', $parameters[1]->getName());
    }

    // --- Helpers ---

    private function makeSettlementContext(
        string $invoiceCurrency,
        string $paymentCurrency,
        string $invoiceAmount,
        string $paymentAmount,
        string $carryingBasis,
        string $settlementBasis,
        ExchangeRateEvidenceStatusEnum $rateEvidenceStatus,
        bool $excludeApControlLine = false,
        bool $excludeCashLine = false,
        bool $excludeApJournal = false,
        bool $excludePaymentJournal = false
    ): array {
        $timestamp = now();
        $vendorId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $sourceCandidateId = (string) Str::ulid();
        $apJournalEntryId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $proposalItemId = (string) Str::ulid();
        $paymentExecutionId = (string) Str::ulid();
        $paymentCandidateId = (string) Str::ulid();
        $paymentJournalEntryId = (string) Str::ulid();
        $allocationId = (string) Str::ulid();
        $rateEvidenceId = (string) Str::ulid();
        $suffix = $this->sequence++;

        // 1. Vendor Category & Vendor
        $vendorCategoryId = (string) Str::ulid();
        DB::table('vendor_categories')->insert([
            'id' => $vendorCategoryId,
            'property_id' => $this->property->id,
            'category_code' => 'FTVC-' . $suffix,
            'name' => 'FX Test Category ' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $this->property->id,
            'company_id' => $this->property->company_id,
            'vendor_category_id' => $vendorCategoryId,
            'vendor_code' => 'FTV' . $suffix,
            'name' => 'FX Test Vendor ' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        // 2. Supplier Invoice
        DB::table('vendor_invoices')->insert([
            'id' => $supplierInvoiceId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'invoice_number' => 'INV-' . $suffix,
            'invoice_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'currency_code' => $invoiceCurrency,
            'status' => 'APPROVED',
            'subtotal' => $invoiceAmount,
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'grand_total' => $invoiceAmount,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        // 3. AP Candidate
        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'AP source candidate',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        // 4. AP Journal Entry
        if (!$excludeApJournal) {
            DB::table('gl_journal_entries')->insert([
                'id' => $apJournalEntryId,
                'property_id' => $this->property->id,
                'transaction_date' => '2026-07-01',
                'posting_date' => null,
                'reference' => 'AP-' . $suffix,
                'description' => 'Posted AP liability',
                'status' => JournalStatusEnum::Draft->value,
                'source_module' => 'Payables',
                'source_type' => 'SupplierInvoice',
                'source_id' => $supplierInvoiceId,
                'journal_candidate_id' => $sourceCandidateId,
                'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $apLines = [
                [
                    'id' => (string) Str::ulid(),
                    'property_id' => $this->property->id,
                    'journal_entry_id' => $apJournalEntryId,
                    'account_id' => $this->makeAccount('EXP-' . $this->sequence++, 'Expense', 'Expense', 'Debit'),
                    'debit_amount' => $carryingBasis,
                    'credit_amount' => '0.00',
                    'memo' => 'Debit expense',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            ];

            $apLines[] = [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $excludeApControlLine
                    ? $this->makeAccount('DUMMY-AP', 'Liability', 'CurrentLiability', 'Credit')
                    : $this->apAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $carryingBasis,
                'memo' => 'Credit AP Control',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            DB::table('gl_journal_entry_lines')->insert($apLines);

            DB::table('gl_journal_entries')
                ->where('id', $apJournalEntryId)
                ->update([
                    'posting_date' => '2026-07-01',
                    'status' => JournalStatusEnum::Posted->value,
                    'updated_at' => $timestamp,
                ]);
        }

        // 5. Payment Proposal
        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'PROP-' . $suffix,
            'currency_code' => $paymentCurrency,
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', $apJournalEntryId),
            'total_amount' => $paymentAmount,
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
            'currency_code' => $paymentCurrency,
            'source_amount' => $paymentAmount,
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        // 6. Payment Execution
        DB::table('payment_executions')->insert([
            'id' => $paymentExecutionId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'payment_proposal_id' => $proposalId,
            'payment_proposal_item_id' => $proposalItemId,
            'source_journal_entry_id' => $apJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => (string) Str::ulid(),
            'cashier_payment_instrument_id' => (string) Str::ulid(),
            'operational_gl_account_id' => $this->cashAccountId,
            'currency_code' => $paymentCurrency,
            'source_amount' => $paymentAmount,
            'executed_by' => $this->actor->id,
            'executed_at' => '2026-07-01 10:00:00',
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        // 7. Payment Candidate
        DB::table('journal_candidates')->insert([
            'id' => $paymentCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Payment candidate',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        // 8. Payment Journal Entry
        if (!$excludePaymentJournal) {
            DB::table('gl_journal_entries')->insert([
                'id' => $paymentJournalEntryId,
                'property_id' => $this->property->id,
                'transaction_date' => '2026-07-01',
                'posting_date' => null,
                'reference' => 'PAY-' . $suffix,
                'description' => 'Posted payment journal',
                'status' => JournalStatusEnum::Draft->value,
                'source_module' => 'GeneralCashier',
                'source_type' => 'PaymentExecution',
                'source_id' => $paymentExecutionId,
                'journal_candidate_id' => $paymentCandidateId,
                'posting_event' => 'SupplierPaymentCashDisbursement',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $paymentLines = [
                [
                    'id' => (string) Str::ulid(),
                    'property_id' => $this->property->id,
                    'journal_entry_id' => $paymentJournalEntryId,
                    'account_id' => $this->apAccountId,
                    'debit_amount' => $settlementBasis,
                    'credit_amount' => '0.00',
                    'memo' => 'Debit AP Control',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            ];

            $paymentLines[] = [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $excludeCashLine
                    ? $this->makeAccount('DUMMY-CASH', 'Asset', 'CurrentAsset', 'Credit')
                    : $this->cashAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $settlementBasis,
                'memo' => 'Credit Cash',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            DB::table('gl_journal_entry_lines')->insert($paymentLines);

            DB::table('gl_journal_entries')
                ->where('id', $paymentJournalEntryId)
                ->update([
                    'posting_date' => '2026-07-01',
                    'status' => JournalStatusEnum::Posted->value,
                    'updated_at' => $timestamp,
                ]);
        }

        // 9. Exchange Rate Evidence
        DB::table('exchange_rate_evidences')->insert([
            'id' => $rateEvidenceId,
            'property_id' => $this->property->id,
            'base_currency' => $invoiceCurrency,
            'quote_currency' => $paymentCurrency,
            'rate' => '1.25000000',
            'quote_convention' => 'BASE_TO_QUOTE',
            'effective_date' => '2026-07-01',
            'source_reference' => 'FX-REF-' . $suffix,
            'status' => $rateEvidenceStatus->value,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'FX-HASH-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        // 10. AP Settlement Allocation
        DB::table('ap_settlement_allocations')->insert([
            'id' => $allocationId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'currency_code' => $paymentCurrency,
            'ap_journal_entry_id' => $apJournalEntryId,
            'payment_journal_entry_id' => $paymentJournalEntryId,
            'payment_execution_id' => $paymentExecutionId,
            'allocation_amount' => $paymentAmount,
            'allocated_by' => $this->actor->id,
            'allocated_at' => '2026-07-01 11:00:00',
            'source_identity_hash' => hash('sha256', 'ALLOC-HASH-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'vendor_id' => $vendorId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'ap_journal_entry_id' => $apJournalEntryId,
            'payment_execution_id' => $paymentExecutionId,
            'payment_journal_entry_id' => $paymentJournalEntryId,
            'rate_evidence_id' => $rateEvidenceId,
            'allocation_id' => $allocationId,
        ];
    }

    private function makeProperty(): Property
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $timestamp = now();
        $suffix = $this->sequence++;

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'FX Eligibility Company ' . $suffix,
            'slug' => 'fx-eligibility-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'FX Eligibility Property ' . $suffix,
            'slug' => 'fx-eligibility-property-' . $suffix,
            'code' => 'FXE' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'USD',
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
            'name' => 'FX Eligibility User ' . $suffix,
            'email' => 'fx-eligibility-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
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

    private function makeAccount(string $code, string $type, string $category, string $normalBalance, bool $cashEquivalent = false): string
    {
        $accountId = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_accounts')->insert([
            'id' => $accountId,
            'property_id' => $this->property->id,
            'code' => $code . '-' . $this->sequence++,
            'name' => $code . ' Account',
            'normal_balance' => $normalBalance,
            'account_type' => $type,
            'account_category' => $category,
            'is_active' => true,
            'is_cash_equivalent' => $cashEquivalent,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $accountId;
    }

    private function makeMapping(
        Property $property,
        OperationalIdentityEnum $identity,
        string $accountId,
        bool $isActive = true,
        string $effectiveFrom = '2026-01-01',
        ?string $effectiveTo = null
    ): void {
        DB::table('gl_operational_identity_mappings')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $property->id,
            'operational_identity' => $identity->value,
            'account_id' => $accountId,
            'cost_center_id' => null,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
