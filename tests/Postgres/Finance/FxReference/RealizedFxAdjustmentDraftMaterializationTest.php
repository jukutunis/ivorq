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
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentCandidateReviewService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentDraftMaterializationService;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class RealizedFxAdjustmentDraftMaterializationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $creator;
    private User $reviewer;
    private User $materializer;
    private RealizedFxAdjustmentCandidateService $candidateService;
    private RealizedFxAdjustmentCandidateReviewService $reviewService;
    private RealizedFxAdjustmentDraftMaterializationService $materializeService;

    private string $apAccountId;
    private string $cashAccountId;
    private string $fxGainAccountId;
    private string $fxLossAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->creator = $this->makeUser();
        $this->reviewer = $this->makeUser();
        $this->materializer = $this->makeUser();

        $this->attachActorToProperty($this->creator, $this->property);
        $this->attachActorToProperty($this->reviewer, $this->property);
        $this->attachActorToProperty($this->materializer, $this->property);

        $this->apAccountId = $this->makeAccount('AP-CTRL', 'Liability', 'CurrentLiability', 'Credit');
        $this->cashAccountId = $this->makeAccount('CASH-BANK', 'Asset', 'CurrentAsset', 'Debit', true);
        $this->fxGainAccountId = $this->makeAccount('FX-GAIN', 'Revenue', 'Revenue', 'Credit');
        $this->fxLossAccountId = $this->makeAccount('FX-LOSS', 'Expense', 'Expense', 'Debit');

        $this->makeMapping($this->property, OperationalIdentityEnum::AP_CONTROL, $this->apAccountId);
        $this->makeMapping($this->property, OperationalIdentityEnum::CASH_AND_BANK, $this->cashAccountId);
        $this->makeMapping($this->property, OperationalIdentityEnum::FX_GAIN, $this->fxGainAccountId);
        $this->makeMapping($this->property, OperationalIdentityEnum::FX_LOSS, $this->fxLossAccountId);

        Permission::firstOrCreate(['name' => RealizedFxAdjustmentCandidateService::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => RealizedFxAdjustmentCandidateReviewService::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => RealizedFxAdjustmentDraftMaterializationService::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.payables.ap-settlement.allocate', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->creator->givePermissionTo([
            RealizedFxAdjustmentCandidateService::PERMISSION,
            'finance.payables.ap-settlement.allocate'
        ]);
        $this->reviewer->givePermissionTo([
            RealizedFxAdjustmentCandidateReviewService::PERMISSION
        ]);
        $this->materializer->givePermissionTo([
            RealizedFxAdjustmentDraftMaterializationService::PERMISSION
        ]);

        $this->candidateService = app(RealizedFxAdjustmentCandidateService::class);
        $this->reviewService = app(RealizedFxAdjustmentCandidateReviewService::class);
        $this->materializeService = app(RealizedFxAdjustmentDraftMaterializationService::class);
    }

    private function assertNoExternalMutations(callable $callback): mixed
    {
        $tables = [
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

    public function test_can_materialize_approved_gain_candidate(): void
    {
        $context = $this->makeSettlementContext(
            carryingBasis: '125.00',
            settlementBasis: '120.00'
        );

        $candidate = $this->candidateService->create($context['allocation_id'], $this->creator);
        $this->reviewService->approve($candidate->id, $this->reviewer->id);

        $draft = $this->assertNoExternalMutations(function () use ($candidate) {
            return $this->materializeService->materialize($candidate->id, $this->materializer->id);
        });

        $this->assertInstanceOf(JournalEntry::class, $draft);
        $this->assertSame(JournalStatusEnum::Draft, $draft->status);
        $this->assertSame($candidate->id, $draft->journal_candidate_id);
        $this->assertSame('GeneralLedger', $draft->source_module);
        $this->assertSame($candidate->source_type, $draft->source_type);
        $this->assertSame($candidate->source_id, $draft->source_id);

        $lines = $draft->lines;
        $this->assertCount(2, $lines);

        $this->assertSame($this->apAccountId, $lines[0]->account_id);
        $this->assertSame('5.00', (string)$lines[0]->debit_amount);
        $this->assertSame('0.00', (string)$lines[0]->credit_amount);

        $this->assertSame($this->fxGainAccountId, $lines[1]->account_id);
        $this->assertSame('0.00', (string)$lines[1]->debit_amount);
        $this->assertSame('5.00', (string)$lines[1]->credit_amount);
    }

    public function test_can_materialize_approved_loss_candidate(): void
    {
        $context = $this->makeSettlementContext(
            carryingBasis: '125.00',
            settlementBasis: '130.00'
        );

        $candidate = $this->candidateService->create($context['allocation_id'], $this->creator);
        $this->reviewService->approve($candidate->id, $this->reviewer->id);

        $draft = $this->assertNoExternalMutations(function () use ($candidate) {
            return $this->materializeService->materialize($candidate->id, $this->materializer->id);
        });

        $this->assertInstanceOf(JournalEntry::class, $draft);
        $this->assertSame(JournalStatusEnum::Draft, $draft->status);

        $lines = $draft->lines;
        $this->assertCount(2, $lines);

        $this->assertSame($this->fxLossAccountId, $lines[0]->account_id);
        $this->assertSame('5.00', (string)$lines[0]->debit_amount);
        $this->assertSame('0.00', (string)$lines[0]->credit_amount);

        $this->assertSame($this->apAccountId, $lines[1]->account_id);
        $this->assertSame('0.00', (string)$lines[1]->debit_amount);
        $this->assertSame('5.00', (string)$lines[1]->credit_amount);
    }

    public function test_pending_or_rejected_candidate_fails_closed(): void
    {
        $context = $this->makeSettlementContext();
        $candidate = $this->candidateService->create($context['allocation_id'], $this->creator);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only APPROVED realized FX candidates can be materialized.');

        $this->materializeService->materialize($candidate->id, $this->materializer->id);
    }

    public function test_actor_permission_property_membership_failures(): void
    {
        $context = $this->makeSettlementContext();
        $candidate = $this->candidateService->create($context['allocation_id'], $this->creator);
        $this->reviewService->approve($candidate->id, $this->reviewer->id);

        $badActor = $this->makeUser();

        $this->expectException(AuthorizationException::class);
        $this->materializeService->materialize($candidate->id, $badActor->id);
    }

    public function test_idempotent_identical_materialization(): void
    {
        $context = $this->makeSettlementContext();
        $candidate = $this->candidateService->create($context['allocation_id'], $this->creator);
        $this->reviewService->approve($candidate->id, $this->reviewer->id);

        $first = $this->materializeService->materialize($candidate->id, $this->materializer->id);
        $second = $this->materializeService->materialize($candidate->id, $this->materializer->id);

        $this->assertSame($first->id, $second->id);
    }

    public function test_conflicting_replay_fails_controlled(): void
    {
        $context = $this->makeSettlementContext();
        $candidate = $this->candidateService->create($context['allocation_id'], $this->creator);
        $this->reviewService->approve($candidate->id, $this->reviewer->id);

        $first = $this->materializeService->materialize($candidate->id, $this->materializer->id);

        // Mutate the draft line account
        DB::table('gl_journal_entry_lines')
            ->where('journal_entry_id', $first->id)
            ->where('account_id', $this->apAccountId)
            ->update(['account_id' => $this->fxLossAccountId]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Existing draft lines conflict with candidate.');

        $this->materializeService->materialize($candidate->id, $this->materializer->id);
    }

    // --- Helpers ---

    private function makeSettlementContext(string $carryingBasis = '125.00', string $settlementBasis = '120.00'): array
    {
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

        DB::table('vendor_invoices')->insert([
            'id' => $supplierInvoiceId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'invoice_number' => 'INV-' . $suffix,
            'invoice_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'currency_code' => 'EUR',
            'status' => 'APPROVED',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'grand_total' => '100.00',
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
            'description' => 'AP source candidate',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

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

        DB::table('gl_journal_entry_lines')->insert([
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
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $this->apAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $carryingBasis,
                'memo' => 'Credit AP Control',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $apJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'updated_at' => $timestamp,
            ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'PROP-' . $suffix,
            'currency_code' => 'USD',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', $apJournalEntryId),
            'total_amount' => '100.00',
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
            'currency_code' => 'USD',
            'source_amount' => '100.00',
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

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
            'currency_code' => 'USD',
            'source_amount' => '100.00',
            'executed_by' => $this->creator->id,
            'executed_at' => '2026-07-01 10:00:00',
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
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
            'description' => 'Payment candidate',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

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

        DB::table('gl_journal_entry_lines')->insert([
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
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $this->cashAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $settlementBasis,
                'memo' => 'Credit Cash',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $paymentJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'updated_at' => $timestamp,
            ]);

        DB::table('exchange_rate_evidences')->insert([
            'id' => $rateEvidenceId,
            'property_id' => $this->property->id,
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'rate' => '1.25000000',
            'quote_convention' => 'BASE_TO_QUOTE',
            'effective_date' => '2026-07-01',
            'source_reference' => 'FX-REF-' . $suffix,
            'status' => ExchangeRateEvidenceStatusEnum::APPROVED->value,
            'recorded_by' => $this->creator->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'FX-HASH-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('ap_settlement_allocations')->insert([
            'id' => $allocationId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'currency_code' => 'USD',
            'ap_journal_entry_id' => $apJournalEntryId,
            'payment_journal_entry_id' => $paymentJournalEntryId,
            'payment_execution_id' => $paymentExecutionId,
            'allocation_amount' => '100.00',
            'allocated_by' => $this->creator->id,
            'allocated_at' => '2026-07-01 11:00:00',
            'source_identity_hash' => hash('sha256', 'ALLOC-HASH-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
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
