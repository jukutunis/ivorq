<?php

namespace Tests\Postgres\Finance\Payables;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Services\GrniClearingApLiabilityCandidateService;
use Modules\Finance\GeneralLedger\Services\JournalCandidateDraftMaterializationService;
use Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService;
use Modules\Finance\GeneralLedger\Services\JournalEntryControlledPostingService;
use Modules\Finance\GeneralLedger\Services\JournalEntryDraftFinalizationAuthorizationService;
use Modules\Finance\Payables\Services\PaymentProposalApprovalService;
use Modules\Finance\Payables\Services\PaymentProposalService;
use Modules\Finance\Payables\Services\SupplierInvoiceApprovalService;
use Modules\Finance\Payables\Services\SupplierInvoiceExceptionReviewService;
use Modules\Finance\Payables\Services\SupplierInvoiceRegistrationService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Services\GeneralCashierOperationalFoundationService;
use Modules\Operations\GeneralCashier\Services\PaymentExecutionService;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class SupplierPaymentLifecycleTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Property $property;
    private User $actor;
    private SupplierInvoiceRegistrationService $invoiceRegistrationService;
    private SupplierInvoiceApprovalService $invoiceApprovalService;
    private GrniClearingApLiabilityCandidateService $grniCandidateService;
    private JournalCandidateReviewService $candidateReviewService;
    private JournalCandidateDraftMaterializationService $draftMaterializationService;
    private JournalEntryDraftFinalizationAuthorizationService $draftAuthorizationService;
    private JournalEntryControlledPostingService $postingService;
    private PaymentProposalService $proposalService;
    private PaymentProposalApprovalService $proposalApprovalService;
    private GeneralCashierOperationalFoundationService $cashierService;
    private PaymentExecutionService $paymentExecutionService;
    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        foreach ($this->paymentLifecyclePermissions() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo($this->paymentLifecyclePermissions());

        $this->invoiceRegistrationService = app(SupplierInvoiceRegistrationService::class);
        $this->invoiceApprovalService = app(SupplierInvoiceApprovalService::class);
        $this->grniCandidateService = app(GrniClearingApLiabilityCandidateService::class);
        $this->candidateReviewService = app(JournalCandidateReviewService::class);
        $this->draftMaterializationService = app(JournalCandidateDraftMaterializationService::class);
        $this->draftAuthorizationService = app(JournalEntryDraftFinalizationAuthorizationService::class);
        $this->postingService = app(JournalEntryControlledPostingService::class);
        $this->proposalService = app(PaymentProposalService::class);
        $this->proposalApprovalService = app(PaymentProposalApprovalService::class);
        $this->cashierService = app(GeneralCashierOperationalFoundationService::class);
        $this->paymentExecutionService = app(PaymentExecutionService::class);
    }

    public function test_authorized_cashier_records_cash_payment_execution_from_approved_proposal_without_accounting_mutation(): void
    {
        $context = $this->makeApprovedPaymentProposalContext();
        $cashier = $this->makeCashierContext($this->actor, $context['accounts']['cash_account_id']);
        $before = $this->controlledSnapshot();
        $sourceBefore = $this->sourceEvidenceSnapshot($context);

        $execution = $this->paymentExecutionService->recordCashExecution(
            $context['proposal_item_id'],
            $cashier['session_id'],
            $cashier['instrument_id'],
            $this->actor
        );

        $this->assertSame($this->property->id, $execution->property_id);
        $this->assertSame($context['proposal']->id, $execution->payment_proposal_id);
        $this->assertSame($context['proposal_item_id'], $execution->payment_proposal_item_id);
        $this->assertSame($context['posted']->id, $execution->source_journal_entry_id);
        $this->assertSame($context['candidate']->id, $execution->source_journal_candidate_id);
        $this->assertSame($context['invoice']->id, $execution->supplier_invoice_id);
        $this->assertSame($cashier['session_id'], $execution->cashier_session_id);
        $this->assertSame($cashier['instrument_id'], $execution->cashier_payment_instrument_id);
        $this->assertSame($context['accounts']['cash_account_id'], $execution->operational_gl_account_id);
        $this->assertSame('125.00', (string) $execution->source_amount);
        $this->assertSame($this->actor->id, $execution->executed_by);
        $this->assertNotNull($execution->executed_at);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'payment_executions' => 1,
        ]);
        $this->assertSame($sourceBefore, $this->sourceEvidenceSnapshot($context));

        $snapshot = $this->paymentExecutionSnapshot($execution->id);
        $repeat = $this->paymentExecutionService->recordCashExecution(
            $context['proposal_item_id'],
            $cashier['session_id'],
            $cashier['instrument_id'],
            $this->actor
        );

        $this->assertSame($execution->id, $repeat->id);
        $this->assertSame($snapshot, $this->paymentExecutionSnapshot($execution->id));
    }

    public function test_payment_execution_fails_closed_for_invalid_actor_scope_source_and_non_cash_context(): void
    {
        $context = $this->makeApprovedPaymentProposalContext();
        $cashier = $this->makeCashierContext($this->actor, $context['accounts']['cash_account_id']);
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $disabled = $this->makeAuthorizedActor($this->property, false);
        $crossProperty = $this->makeAuthorizedActor($this->makeProperty());

        foreach ([null, $unauthorized, $disabled, $crossProperty] as $invalidActor) {
            $before = $this->controlledSnapshot();

            try {
                $this->paymentExecutionService->recordCashExecution(
                    $context['proposal_item_id'],
                    $cashier['session_id'],
                    $cashier['instrument_id'],
                    $invalidActor
                );
                $this->fail('Invalid Payment Execution actor must fail closed.');
            } catch (AuthorizationException) {
                $this->assertControlledSnapshotUnchanged($before);
            }
        }

        $draftContext = $this->makePaymentProposalContext(false, $context['accounts']);
        $before = $this->controlledSnapshot();

        try {
            $this->paymentExecutionService->recordCashExecution(
                $draftContext['proposal_item_id'],
                $cashier['session_id'],
                $cashier['instrument_id'],
                $this->actor
            );
            $this->fail('Unapproved Payment Proposal Item must not execute.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Only approved', $exception->getMessage());
            $this->assertControlledSnapshotUnchanged($before);
        }

        $bank = $this->makeCashierContext($this->actor, $context['accounts']['cash_account_id'], 'BANK');
        $before = $this->controlledSnapshot();

        try {
            $this->paymentExecutionService->recordCashExecution(
                $context['proposal_item_id'],
                $bank['session_id'],
                $bank['instrument_id'],
                $this->actor
            );
            $this->fail('BANK instrument execution must not be accepted in the cash-only slice.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Only CASH', $exception->getMessage());
            $this->assertControlledSnapshotUnchanged($before);
        }
    }

    private function paymentLifecyclePermissions(): array
    {
        return [
            SupplierInvoiceRegistrationService::PERMISSION,
            SupplierInvoiceExceptionReviewService::PERMISSION,
            SupplierInvoiceApprovalService::PERMISSION,
            GrniClearingApLiabilityCandidateService::PERMISSION,
            JournalCandidateReviewService::PERMISSION,
            'finance.journal-candidate.materialize-draft',
            'finance.journal-entry-draft.authorize-finalization',
            'finance.journal-entry.post',
            PaymentProposalService::CREATE_PERMISSION,
            PaymentProposalService::CANCEL_PERMISSION,
            PaymentProposalApprovalService::SUBMIT_PERMISSION,
            PaymentProposalApprovalService::APPROVE_PERMISSION,
            GeneralCashierOperationalFoundationService::OPEN_PERMISSION,
            PaymentExecutionService::PERMISSION,
        ];
    }

    private function makeApprovedPaymentProposalContext(?array $accounts = null): array
    {
        return $this->makePaymentProposalContext(true, $accounts);
    }

    private function makePaymentProposalContext(bool $approve, ?array $accounts = null): array
    {
        $context = $this->makePostedApLiabilityContext($accounts);
        $proposal = $this->proposalService->createDraft([$context['posted']->id], $this->actor);

        if ($approve) {
            $this->proposalApprovalService->submit($proposal->id, $this->actor);
            $approver = $this->makeAuthorizedActor($this->property);
            $proposal = $this->proposalApprovalService->approve($proposal->id, $approver);
        }

        return $context + [
            'proposal' => $proposal,
            'proposal_item_id' => $proposal->items->first()->id,
        ];
    }

    private function makePostedApLiabilityContext(?array $accounts = null): array
    {
        $context = $this->makeApprovedGrniApDraft($accounts);
        $draft = $context['draft'];
        $this->openPostingControls($this->property, $draft->transaction_date->toDateString());
        $this->draftAuthorizationService->authorize($draft->id, $this->actor->id);
        $posted = $this->postingService->post($draft->id, $this->actor->id);

        return $context + [
            'posted' => $posted,
        ];
    }

    private function makeApprovedGrniApDraft(?array $accounts = null): array
    {
        $context = $this->makeApprovedGrniApCandidate($accounts);
        $candidate = $this->candidateReviewService->approve($context['candidate']->id, $this->actor->id);
        $draft = $this->draftMaterializationService->materialize($candidate->id, $this->actor->id);

        return $context + [
            'candidate' => $candidate,
            'draft' => $draft,
        ];
    }

    private function makeApprovedGrniApCandidate(?array $accounts = null): array
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $accounts ??= $this->makeAccountMappings($this->property);
        $result = $this->invoiceRegistrationService->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $invoice = $this->invoiceApprovalService->approve($result['invoice']->id, $this->actor);
        $grni = $this->makePostedGrniEvidence($fixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
        ]);

        $this->actingAs($this->actor);
        $candidate = $this->grniCandidateService->createForSupplierInvoice($invoice->id);

        return [
            'fixture' => $fixture,
            'accounts' => $accounts,
            'invoice' => $invoice,
            'grni' => $grni,
            'candidate' => $candidate,
        ];
    }

    private function makeCashierContext(User $actor, string $accountId, string $type = 'CASH'): array
    {
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $session = $this->cashierService->openSession($actor);
        $instrumentId = (string) Str::ulid();
        $timestamp = now();

        DB::table('cashier_payment_instruments')->insert([
            'id' => $instrumentId,
            'property_id' => $this->property->id,
            'name' => $type . ' Instrument ' . $this->sequence++,
            'type' => $type,
            'operational_gl_account_id' => $accountId,
            'is_active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'session_id' => $session->id,
            'instrument_id' => $instrumentId,
        ];
    }

    private function makeAccountMappings(Property $property): array
    {
        $inventoryAccountId = $this->makeAccount($property, 'INV-' . $this->sequence++, 'Inventory Control', 'Asset', 'CurrentAsset', 'Debit');
        $grniAccountId = $this->makeAccount($property, 'GRNI-' . $this->sequence++, 'GRNI Receipt Liability', 'Liability', 'CurrentLiability', 'Credit');
        $apAccountId = $this->makeAccount($property, 'AP-' . $this->sequence++, 'AP Control Liability', 'Liability', 'CurrentLiability', 'Credit');
        $cashAccountId = $this->makeAccount($property, 'CASH-' . $this->sequence++, 'Cash on Hand', 'Asset', 'CurrentAsset', 'Debit', true, true);

        return [
            'inventory_account_id' => $inventoryAccountId,
            'grni_account_id' => $grniAccountId,
            'ap_account_id' => $apAccountId,
            'cash_account_id' => $cashAccountId,
            'inventory_mapping_id' => $this->makeOperationalIdentityMapping($property, 'INVENTORY', $inventoryAccountId),
            'grni_mapping_id' => $this->makeOperationalIdentityMapping($property, 'GRNI_RECEIPT', $grniAccountId),
            'ap_mapping_id' => $this->makeOperationalIdentityMapping($property, 'AP_CONTROL', $apAccountId),
            'cash_mapping_id' => $this->makeOperationalIdentityMapping($property, 'CASH_AND_BANK', $cashAccountId),
        ];
    }

    private function makeAccount(
        Property $property,
        string $code,
        string $name,
        string $accountType,
        string $accountCategory,
        string $normalBalance,
        bool $active = true,
        bool $cashEquivalent = false,
    ): string {
        $accountId = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_accounts')->insert([
            'id' => $accountId,
            'property_id' => $property->id,
            'code' => $code,
            'name' => $name,
            'normal_balance' => $normalBalance,
            'account_type' => $accountType,
            'account_category' => $accountCategory,
            'is_active' => $active,
            'is_cash_equivalent' => $cashEquivalent,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $accountId;
    }

    private function makeOperationalIdentityMapping(Property $property, string $identity, string $accountId): string
    {
        $mappingId = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_operational_identity_mappings')->insert([
            'id' => $mappingId,
            'property_id' => $property->id,
            'operational_identity' => $identity,
            'account_id' => $accountId,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $mappingId;
    }

    private function openPostingControls(Property $property, string $date): void
    {
        $timestamp = now();
        $year = (int) date('Y', strtotime($date));
        $month = (int) date('m', strtotime($date));

        DB::table('gl_financial_periods')->updateOrInsert(
            [
                'property_id' => $property->id,
                'period_year' => $year,
                'period_month' => $month,
            ],
            [
                'id' => (string) Str::ulid(),
                'status' => 'Open',
                'opened_at' => $timestamp,
                'closed_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );

        DB::table('property_business_dates')->updateOrInsert(
            [
                'property_id' => $property->id,
                'business_date' => $date,
            ],
            [
                'id' => (string) Str::ulid(),
                'status' => 'Open',
                'is_open' => true,
                'opened_at' => $timestamp,
                'closed_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }

    private function makePostedGrniEvidence(array $fixture, array $overrides): array
    {
        $timestamp = now();
        $receiptId = (string) Str::ulid();
        $receiptLineId = (string) Str::ulid();
        $candidateId = (string) Str::ulid();
        $journalEntryId = (string) Str::ulid();

        DB::table('inventory_receipts')->insert([
            'id' => $receiptId,
            'property_id' => $fixture['property_id'],
            'receipt_number' => 'IR-' . $this->sequence++,
            'supplier_name' => 'Vendor GRNI source',
            'external_reference' => $fixture['goods_receipt_id'],
            'receiving_document_id' => $fixture['goods_receipt_id'],
            'status' => 'posted',
            'received_at' => '2026-06-30 00:00:00',
            'remarks' => 'GRNI eligibility source fixture',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'posted_by' => $this->actor->id,
            'posted_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('inventory_receipt_lines')->insert([
            'id' => $receiptLineId,
            'property_id' => $fixture['property_id'],
            'receipt_id' => $receiptId,
            'item_id' => $fixture['inventory_item_id'],
            'location_id' => $fixture['location_id'],
            'quantity' => 10,
            'unit_cost' => 12.50,
            'line_total' => 125,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $candidateId,
            'property_id' => $fixture['property_id'],
            'source_type' => 'InventoryReceipt',
            'source_id' => $receiptId,
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => 'APPROVED',
            'candidate_date' => '2026-06-30',
            'description' => 'GRNI Accrual for Receipt ' . $receiptId,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode([
                'receipt_id' => $receiptId,
                'receipt_number' => 'IR source',
                'supplier_name' => 'Vendor GRNI source',
                'total_cost' => 125,
            ]),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        foreach ([['INVENTORY', 'DEBIT'], ['GRNI_RECEIPT', 'CREDIT']] as [$identity, $entryType]) {
            DB::table('journal_candidate_lines')->insert([
                'id' => (string) Str::ulid(),
                'journal_candidate_id' => $candidateId,
                'operational_identity' => $identity,
                'entry_type' => $entryType,
                'amount' => 125,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        DB::table('gl_journal_entries')->insert([
            'id' => $journalEntryId,
            'property_id' => $fixture['property_id'],
            'transaction_date' => '2026-06-30',
            'posting_date' => '2026-06-30',
            'reference' => $receiptId,
            'description' => 'Posted GRNI accrual source',
            'status' => 'Draft',
            'source_module' => 'Inventory',
            'source_type' => 'InventoryReceipt',
            'source_id' => $receiptId,
            'journal_candidate_id' => $candidateId,
            'posting_event' => 'InventoryReceiptAccrual',
            'draft_finalization_authorized_by' => $this->actor->id,
            'draft_finalization_authorized_at' => $timestamp,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $fixture['property_id'],
                'journal_entry_id' => $journalEntryId,
                'account_id' => $overrides['inventory_account_id'],
                'debit_amount' => 125,
                'credit_amount' => 0,
                'memo' => 'Posted inventory receipt debit source',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $fixture['property_id'],
                'journal_entry_id' => $journalEntryId,
                'account_id' => $overrides['grni_account_id'],
                'debit_amount' => 0,
                'credit_amount' => 125,
                'memo' => 'Posted GRNI liability credit source',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $journalEntryId)
            ->update([
                'status' => 'Posted',
                'posted_by' => $this->actor->id,
                'posted_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

        return [
            'inventory_receipt_id' => $receiptId,
            'inventory_receipt_line_id' => $receiptLineId,
            'journal_candidate_id' => $candidateId,
            'journal_entry_id' => $journalEntryId,
        ];
    }

    private function makePurchasingFixture(Property $property): array
    {
        $vendorId = $this->makeVendor($property, 'SUP-' . $this->sequence++);
        $departmentId = (string) Str::ulid();
        $requestId = (string) Str::ulid();
        $purchaseOrderId = (string) Str::ulid();
        $unitId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $purchaseOrderLineId = (string) Str::ulid();
        $goodsReceiptId = (string) Str::ulid();
        $goodsReceiptLineId = (string) Str::ulid();
        $timestamp = now();

        DB::table('departments')->insert([
            'id' => $departmentId,
            'property_id' => $property->id,
            'name' => 'Purchasing ' . $this->sequence,
            'code' => 'PUR-' . $this->sequence++,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('purchase_requests')->insert([
            'id' => $requestId,
            'property_id' => $property->id,
            'request_no' => 'PR-' . $this->sequence++,
            'department_id' => $departmentId,
            'requester_id' => $this->actor->id,
            'required_date' => '2026-07-05',
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'estimated_total' => 125,
            'status' => 'APPROVED',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('purchase_orders')->insert([
            'id' => $purchaseOrderId,
            'property_id' => $property->id,
            'po_no' => 'PO-' . $this->sequence++,
            'vendor_id' => $vendorId,
            'purchase_request_id' => $requestId,
            'issue_date' => '2026-06-29',
            'expected_delivery_date' => '2026-07-05',
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'subtotal' => 125,
            'tax_amount' => 0,
            'total_amount' => 125,
            'received_total' => 125,
            'status' => 'ISSUED',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('inventory_categories')->insert([
            'id' => $categoryId,
            'property_id' => $property->id,
            'name' => 'Food ' . $this->sequence++,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('inventory_units')->insert([
            'id' => $unitId,
            'property_id' => $property->id,
            'code' => 'EA-' . $this->sequence++,
            'name' => 'Each',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('inventory_items')->insert([
            'id' => $itemId,
            'property_id' => $property->id,
            'sku' => 'SKU-' . $this->sequence++,
            'name' => 'Supplier payment test item',
            'category_id' => $categoryId,
            'inventory_type' => 'stock',
            'criticality' => 'low',
            'is_batch_tracked' => false,
            'is_expiry_tracked' => false,
            'weighted_average_cost' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('inventory_locations')->insert([
            'id' => $locationId,
            'property_id' => $property->id,
            'name' => 'Main Store ' . $this->sequence++,
            'type' => 'storeroom',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('purchase_order_lines')->insert([
            'id' => $purchaseOrderLineId,
            'purchase_order_id' => $purchaseOrderId,
            'inventory_item_id' => $itemId,
            'description' => 'Supplier payment test item',
            'ordered_quantity' => 10,
            'received_quantity' => 10,
            'invoiced_quantity' => 0,
            'receiving_tolerance_percent' => 0,
            'unit_id' => $unitId,
            'unit_cost' => 12.50,
            'line_total' => 125,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('receiving_documents')->insert([
            'id' => $goodsReceiptId,
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'purchase_order_id' => $purchaseOrderId,
            'grn_number' => 'GRN-' . $this->sequence++,
            'status' => 'approved',
            'received_at' => '2026-06-30 00:00:00',
            'received_by' => $this->actor->id,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('receiving_lines')->insert([
            'id' => $goodsReceiptLineId,
            'receiving_document_id' => $goodsReceiptId,
            'purchase_order_line_id' => $purchaseOrderLineId,
            'inventory_item_id' => $itemId,
            'inventory_unit_id' => $unitId,
            'destination_location_id' => $locationId,
            'description' => 'Supplier payment test item',
            'received_quantity' => 10,
            'unit_cost' => 12.50,
            'line_total' => 125,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'purchase_order_id' => $purchaseOrderId,
            'purchase_order_line_id' => $purchaseOrderLineId,
            'goods_receipt_id' => $goodsReceiptId,
            'goods_receipt_line_id' => $goodsReceiptLineId,
            'inventory_item_id' => $itemId,
            'location_id' => $locationId,
            'currency_code' => 'IDR',
        ];
    }

    private function invoicePayload(array $fixture): array
    {
        return [
            'property_id' => $fixture['property_id'],
            'vendor_id' => $fixture['vendor_id'],
            'purchase_order_id' => $fixture['purchase_order_id'],
            'goods_receipt_id' => $fixture['goods_receipt_id'],
            'invoice_number' => 'SINV-PAY-' . $this->sequence++,
            'invoice_date' => '2026-06-30',
            'currency_code' => $fixture['currency_code'],
            'tax_amount' => 0,
            'discount_amount' => 0,
            'remarks' => 'Supplier payment lifecycle test',
            'lines' => [[
                'purchase_order_line_id' => $fixture['purchase_order_line_id'],
                'goods_receipt_line_id' => $fixture['goods_receipt_line_id'],
                'inventory_item_id' => $fixture['inventory_item_id'],
                'description' => 'Supplier payment test item',
                'quantity' => 10,
                'unit_price' => 12.50,
                'line_total' => 125,
            ]],
        ];
    }

    private function makeVendor(Property $property, string $code): string
    {
        $categoryId = (string) Str::ulid();
        $vendorId = (string) Str::ulid();
        $timestamp = now();

        DB::table('vendor_categories')->insert([
            'id' => $categoryId,
            'property_id' => $property->id,
            'category_code' => 'VC-' . $code,
            'name' => 'Vendor Category ' . $code,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $property->id,
            'vendor_category_id' => $categoryId,
            'vendor_code' => $code,
            'name' => 'Vendor ' . $code,
            'default_currency_code' => 'IDR',
            'is_active' => true,
            'is_approved' => true,
            'performance_score' => 0,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $vendorId;
    }

    private function makeAuthorizedActor(Property $property, bool $active = true): User
    {
        $actor = $this->makeUser($active);
        $this->attachActorToProperty($actor, $property);

        if ($active) {
            $actor->givePermissionTo($this->paymentLifecyclePermissions());
        } else {
            $actor->givePermissionTo([PaymentExecutionService::PERMISSION]);
        }

        return $actor;
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
            'name' => 'Supplier Payment Company ' . $suffix,
            'slug' => 'supplier-payment-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Supplier Payment Property ' . $suffix,
            'slug' => 'supplier-payment-property-' . $suffix,
            'code' => 'SPP' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Property::query()->findOrFail($propertyId);
    }

    private function makeUser(bool $active = true): User
    {
        $userId = (string) Str::ulid();
        $suffix = $this->sequence++;
        $timestamp = now();

        DB::table('users')->insert([
            'id' => $userId,
            'is_system_admin' => false,
            'name' => 'Supplier Payment User ' . $suffix,
            'email' => 'supplier-payment-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => $active,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'payment_executions',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'financial_periods',
            'gl_financial_periods',
            'property_business_dates',
            'payment_proposals',
            'payment_proposal_items',
            'payment_vouchers',
            'payment_voucher_lines',
            'accounts_payables',
            'inventory_transactions',
            'cost_ledger_entries',
            'cost_avco_states',
            'inventory_receipts',
            'inventory_receipt_lines',
            'receiving_documents',
            'receiving_lines',
            'cashier_sessions',
            'cashier_payment_instruments',
        ];

        $snapshot = [];

        foreach ($tables as $table) {
            $snapshot[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        return $snapshot;
    }

    private function assertControlledSnapshotUnchanged(array $before): void
    {
        $this->assertSame($before, $this->controlledSnapshot());
    }

    private function assertControlledSnapshotUnchangedExcept(array $before, array $allowedDeltas): void
    {
        $after = $this->controlledSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count + ($allowedDeltas[$table] ?? 0), $after[$table], $table);
        }
    }

    private function sourceEvidenceSnapshot(array $context): array
    {
        return [
            'proposal' => (array) DB::table('payment_proposals')->where('id', $context['proposal']->id)->first(),
            'proposal_item' => (array) DB::table('payment_proposal_items')->where('id', $context['proposal_item_id'])->first(),
            'invoice' => (array) DB::table('vendor_invoices')->where('id', $context['invoice']->id)->first(),
            'source_journal' => (array) DB::table('gl_journal_entries')->where('id', $context['posted']->id)->first(),
            'purchase_order' => (array) DB::table('purchase_orders')->where('id', $context['fixture']['purchase_order_id'])->first(),
            'receiving' => (array) DB::table('receiving_documents')->where('id', $context['fixture']['goods_receipt_id'])->first(),
        ];
    }

    private function paymentExecutionSnapshot(string $executionId): array
    {
        return (array) DB::table('payment_executions')
            ->where('id', $executionId)
            ->first([
                'property_id',
                'vendor_id',
                'payment_proposal_id',
                'payment_proposal_item_id',
                'source_journal_entry_id',
                'source_journal_candidate_id',
                'supplier_invoice_id',
                'cashier_session_id',
                'cashier_payment_instrument_id',
                'operational_gl_account_id',
                'currency_code',
                'source_amount',
                'executed_by',
                'executed_at',
                'source_snapshot',
                'created_by',
                'created_at',
            ]);
    }
}
