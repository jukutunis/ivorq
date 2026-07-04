<?php

namespace Tests\Postgres\Finance\FxReference;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\FxReference\Enums\ExchangeRateEvidenceStatusEnum;
use Modules\Finance\FxReference\Services\FxAdjustmentEligibilityService;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class FxAdjustmentEligibilityFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private FxAdjustmentEligibilityService $service;

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
            'name' => FxAdjustmentEligibilityService::PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo(FxAdjustmentEligibilityService::PERMISSION);

        $this->service = app(FxAdjustmentEligibilityService::class);
    }

    private function assertNoExternalMutations(callable $callback): mixed
    {
        $tables = [
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'journal_candidates',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
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

    public function test_happy_path_eligible_allocation(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertTrue($result['eligible']);
        $this->assertSame('ELIGIBLE', $result['reason_code']);
        $this->assertSame($this->property->id, $result['source_owned_ids']['property_id']);
        $this->assertSame($context['vendor_id'], $result['source_owned_ids']['vendor_id']);
        $this->assertSame($context['allocation_id'], $result['source_owned_ids']['allocation_id']);
        $this->assertSame($context['ap_journal_entry_id'], $result['source_owned_ids']['ap_journal_entry_id']);
        $this->assertSame($context['payment_journal_entry_id'], $result['source_owned_ids']['payment_journal_entry_id']);
        $this->assertSame($context['payment_execution_id'], $result['source_owned_ids']['payment_execution_id']);
        $this->assertSame($context['supplier_invoice_id'], $result['source_owned_ids']['supplier_invoice_id']);

        $this->assertSame('EUR', $result['source_owned_currency_codes']['invoice_currency']);
        $this->assertSame('USD', $result['source_owned_currency_codes']['payment_currency']);

        $this->assertSame($context['rate_evidence_id'], $result['approved_exchange_rate_evidence_id']);
        $this->assertNotNull($result['fx_gain_mapping_id']);
        $this->assertNotNull($result['fx_loss_mapping_id']);
        $this->assertNotEmpty($result['immutable_evidence_snapshots']);
    }

    public function test_same_currency_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'USD',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('SAME_CURRENCY', $result['reason_code']);
    }

    public function test_no_approved_rate_evidence_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::RECORDED
        );

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('NO_APPROVED_RATE_EVIDENCE', $result['reason_code']);
    }

    public function test_inactive_actor_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $inactiveActor = $this->makeUser();
        $this->attachActorToProperty($inactiveActor, $this->property);
        DB::table('users')->where('id', $inactiveActor->id)->update(['is_active' => false]);

        $result = $this->assertNoExternalMutations(function () use ($context, $inactiveActor) {
            return $this->service->evaluate($context['allocation_id'], $inactiveActor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('INACTIVE_ACTOR', $result['reason_code']);
    }

    public function test_unauthorized_actor_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $unauthorizedActor = $this->makeUser();
        $this->attachActorToProperty($unauthorizedActor, $this->property);

        $result = $this->assertNoExternalMutations(function () use ($context, $unauthorizedActor) {
            return $this->service->evaluate($context['allocation_id'], $unauthorizedActor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('UNAUTHORIZED_ACTOR', $result['reason_code']);
    }

    public function test_inactive_membership_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $otherActor = $this->makeUser();
        $otherActor->givePermissionTo(FxAdjustmentEligibilityService::PERMISSION);

        $otherActor->properties()->syncWithoutDetaching([
            $this->property->id => [
                'is_default' => true,
                'status' => 'inactive',
                'joined_at' => now(),
            ],
        ]);

        $result = $this->assertNoExternalMutations(function () use ($context, $otherActor) {
            return $this->service->evaluate($context['allocation_id'], $otherActor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('PROPERTY_ACCESS_DENIED', $result['reason_code']);
    }

    public function test_cross_property_membership_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $otherProperty = $this->makeProperty();
        $otherActor = $this->makeUser();
        $this->attachActorToProperty($otherActor, $otherProperty);
        $otherActor->givePermissionTo(FxAdjustmentEligibilityService::PERMISSION);

        $result = $this->assertNoExternalMutations(function () use ($context, $otherActor) {
            return $this->service->evaluate($context['allocation_id'], $otherActor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('PROPERTY_ACCESS_DENIED', $result['reason_code']);
    }

    public function test_conflicting_provenance_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $otherProperty = $this->makeProperty();
        DB::table('vendor_invoices')->where('id', $context['supplier_invoice_id'])->update([
            'property_id' => $otherProperty->id,
        ]);

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('CONFLICTING_PROVENANCE', $result['reason_code']);
    }

    public function test_missing_provenance_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        DB::table('payment_executions')->where('id', $context['payment_execution_id'])->delete();

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('MISSING_PROVENANCE', $result['reason_code']);
    }

    public function test_missing_ap_basis_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED,
            excludeApControlLine: true
        );

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('MISSING_BASIS', $result['reason_code']);
    }

    public function test_missing_cash_basis_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED,
            excludeApControlLine: false,
            excludeCashLine: true
        );

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('MISSING_BASIS', $result['reason_code']);
    }

    public function test_inactive_mapping_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        DB::table('gl_operational_identity_mappings')
            ->where('property_id', $this->property->id)
            ->where('operational_identity', OperationalIdentityEnum::FX_GAIN->value)
            ->update(['is_active' => false]);

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('INVALID_MAPPING', $result['reason_code']);
    }

    public function test_expired_mapping_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        DB::table('gl_operational_identity_mappings')
            ->where('property_id', $this->property->id)
            ->where('operational_identity', OperationalIdentityEnum::FX_LOSS->value)
            ->update(['effective_to' => '2026-06-30']);

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('INVALID_MAPPING', $result['reason_code']);
    }

    public function test_cross_property_mapping_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $otherProperty = $this->makeProperty();
        DB::table('gl_operational_identity_mappings')
            ->where('property_id', $this->property->id)
            ->where('operational_identity', OperationalIdentityEnum::FX_GAIN->value)
            ->update(['property_id' => $otherProperty->id]);

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('INVALID_MAPPING', $result['reason_code']);
    }

    public function test_ambiguous_mapping_fails_closed(): void
    {
        $context = $this->makeSettlementContext(
            invoiceCurrency: 'EUR',
            paymentCurrency: 'USD',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            rateEvidenceStatus: ExchangeRateEvidenceStatusEnum::APPROVED
        );

        $secondFxGainAccount = $this->makeAccount('FX-GAIN-2', 'Revenue', 'Revenue', 'Credit');
        $this->makeMapping($this->property, OperationalIdentityEnum::FX_GAIN, $secondFxGainAccount);

        $result = $this->assertNoExternalMutations(function () use ($context) {
            return $this->service->evaluate($context['allocation_id'], $this->actor);
        });

        $this->assertFalse($result['eligible']);
        $this->assertSame('AMBIGUOUS_MAPPING', $result['reason_code']);
    }

    public function test_caller_cannot_inject_values(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'evaluate');
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
        ExchangeRateEvidenceStatusEnum $rateEvidenceStatus,
        bool $excludeApControlLine = false,
        bool $excludeCashLine = false
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
                'debit_amount' => $invoiceAmount,
                'credit_amount' => '0.00',
                'memo' => 'Debit expense',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        ];

        if (!$excludeApControlLine) {
            $apLines[] = [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $this->apAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $invoiceAmount,
                'memo' => 'Credit AP Control',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        DB::table('gl_journal_entry_lines')->insert($apLines);

        DB::table('gl_journal_entries')
            ->where('id', $apJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'updated_at' => $timestamp,
            ]);

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
                'debit_amount' => $paymentAmount,
                'credit_amount' => '0.00',
                'memo' => 'Debit AP Control',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        ];

        if (!$excludeCashLine) {
            $paymentLines[] = [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $this->cashAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $paymentAmount,
                'memo' => 'Credit Cash',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        DB::table('gl_journal_entry_lines')->insert($paymentLines);

        DB::table('gl_journal_entries')
            ->where('id', $paymentJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'updated_at' => $timestamp,
            ]);

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
