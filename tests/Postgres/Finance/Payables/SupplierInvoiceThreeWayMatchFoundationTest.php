<?php

namespace Tests\Postgres\Finance\Payables;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Services\GrniClearingApLiabilityCandidateService;
use Modules\Finance\GeneralLedger\Services\JournalEntryControlledPostingService;
use Modules\Finance\GeneralLedger\Services\JournalEntryDraftFinalizationAuthorizationService;
use Modules\Finance\GeneralLedger\Services\JournalCandidateDraftMaterializationService;
use Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService;
use Modules\Finance\Payables\Enums\MatchExceptionEnum;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Services\GrniClearingAllocationEligibilityService;
use Modules\Finance\Payables\Services\SupplierInvoiceApprovalService;
use Modules\Finance\Payables\Services\SupplierInvoiceExceptionReviewService;
use Modules\Finance\Payables\Services\SupplierInvoiceRegistrationService;
use Modules\Finance\Payables\Services\ThreeWayMatchingEngine;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class SupplierInvoiceThreeWayMatchFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Property $property;
    private User $actor;
    private SupplierInvoiceRegistrationService $service;
    private SupplierInvoiceExceptionReviewService $exceptionReviewService;
    private SupplierInvoiceApprovalService $approvalService;
    private GrniClearingAllocationEligibilityService $grniEligibilityService;
    private GrniClearingApLiabilityCandidateService $grniCandidateService;
    private JournalCandidateReviewService $candidateReviewService;
    private JournalCandidateDraftMaterializationService $draftMaterializationService;
    private JournalEntryDraftFinalizationAuthorizationService $draftFinalizationAuthorizationService;
    private JournalEntryControlledPostingService $controlledPostingService;
    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);

        foreach ($this->supplierInvoicePermissions() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo($this->supplierInvoicePermissions());

        $this->service = app(SupplierInvoiceRegistrationService::class);
        $this->exceptionReviewService = app(SupplierInvoiceExceptionReviewService::class);
        $this->approvalService = app(SupplierInvoiceApprovalService::class);
        $this->grniEligibilityService = app(GrniClearingAllocationEligibilityService::class);
        $this->grniCandidateService = app(GrniClearingApLiabilityCandidateService::class);
        $this->candidateReviewService = app(JournalCandidateReviewService::class);
        $this->draftMaterializationService = app(JournalCandidateDraftMaterializationService::class);
        $this->draftFinalizationAuthorizationService = app(JournalEntryDraftFinalizationAuthorizationService::class);
        $this->controlledPostingService = app(JournalEntryControlledPostingService::class);
    }

    public function test_authorized_actor_registers_supplier_invoice_and_matched_result_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture);
        $controlledBefore = $this->controlledSnapshot();
        $sourceBefore = $this->sourceSnapshot($fixture);

        $result = $this->service->registerAndMatch($payload, $this->actor);
        $invoice = $result['invoice'];
        $match = $result['match'];

        $this->assertDatabaseHas('vendor_invoices', [
            'id' => $invoice->id,
            'property_id' => $this->property->id,
            'vendor_id' => $fixture['vendor_id'],
            'purchase_order_id' => $fixture['purchase_order_id'],
            'goods_receipt_id' => $fixture['goods_receipt_id'],
            'invoice_number' => $payload['invoice_number'],
            'currency_code' => 'IDR',
            'status' => 'REGISTERED',
            'created_by' => $this->actor->id,
        ]);
        $this->assertSame($payload['invoice_number'], $invoice->invoice_number);
        $this->assertEquals('2026-06-30', $invoice->invoice_date->toDateString());
        $this->assertEquals('125.00', $invoice->grand_total);
        $this->assertCount(1, $invoice->lines);

        $line = $invoice->lines->first();
        $this->assertSame($fixture['purchase_order_line_id'], $line->purchase_order_line_id);
        $this->assertSame($fixture['goods_receipt_line_id'], $line->goods_receipt_line_id);
        $this->assertSame($fixture['inventory_item_id'], $line->inventory_item_id);
        $this->assertEquals('10.000', $line->quantity);
        $this->assertEquals('12.50', $line->unit_price);
        $this->assertEquals('125.00', $line->line_total);

        $this->assertSame(MatchStatusEnum::Matched, $match->status);
        $this->assertNull($match->exception_code);
        $this->assertCount(1, $match->lines);
        $this->assertEquals('0.0000', $match->total_quantity_variance);
        $this->assertEquals('0.00', $match->total_price_variance);
        $this->assertEquals('0.00', $match->total_amount_variance);

        $this->assertControlledSnapshotUnchanged($controlledBefore);
        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
    }

    public function test_registration_failure_leaves_no_partial_header_line_or_match_evidence(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture, [], [
            'purchase_order_line_id' => (string) Str::ulid(),
        ]);
        $before = $this->invoiceEvidenceCounts();

        try {
            $this->service->registerAndMatch($payload, $this->actor);
            $this->fail('Registration with unresolved line provenance must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('purchase order line', $exception->getMessage());
        }

        $this->assertSame($before, $this->invoiceEvidenceCounts());
        $this->assertDatabaseMissing('vendor_invoices', [
            'invoice_number' => $payload['invoice_number'],
        ]);
    }

    public function test_quantity_variance_creates_exception_evidence_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture, [], [
            'quantity' => 8,
            'unit_price' => 12.50,
        ]);
        $before = $this->controlledSnapshot();

        $match = $this->service->registerAndMatch($payload, $this->actor)['match'];

        $this->assertSame(MatchStatusEnum::Exception, $match->status);
        $this->assertSame(MatchExceptionEnum::QuantityVariance, $match->exception_code);
        $this->assertEquals('-2.0000', $match->total_quantity_variance);
        $this->assertEquals('-25.00', $match->total_amount_variance);
        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_price_variance_creates_exception_evidence_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture, [], [
            'quantity' => 10,
            'unit_price' => 13.00,
        ]);
        $before = $this->controlledSnapshot();

        $match = $this->service->registerAndMatch($payload, $this->actor)['match'];

        $this->assertSame(MatchStatusEnum::Exception, $match->status);
        $this->assertSame(MatchExceptionEnum::PriceVariance, $match->exception_code);
        $this->assertEquals('0.50', $match->total_price_variance);
        $this->assertEquals('5.00', $match->total_amount_variance);
        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_vendor_mismatch_creates_controlled_exception_without_source_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $invoiceVendorId = $this->makeVendor($this->property, 'ALT-' . $this->sequence++);
        $payload = $this->invoicePayload($fixture, [
            'vendor_id' => $invoiceVendorId,
        ]);
        $before = $this->controlledSnapshot();
        $sourceBefore = $this->sourceSnapshot($fixture);

        $match = $this->service->registerAndMatch($payload, $this->actor)['match'];

        $this->assertSame(MatchStatusEnum::Exception, $match->status);
        $this->assertSame(MatchExceptionEnum::VendorMismatch, $match->exception_code);
        $this->assertCount(0, $match->lines);
        $this->assertControlledSnapshotUnchanged($before);
        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
    }

    public function test_missing_receiving_creates_exception_evidence_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture, [
            'goods_receipt_id' => null,
        ], [
            'goods_receipt_line_id' => null,
        ]);
        $before = $this->controlledSnapshot();

        $match = $this->service->registerAndMatch($payload, $this->actor)['match'];

        $this->assertSame(MatchStatusEnum::Exception, $match->status);
        $this->assertSame(MatchExceptionEnum::MissingGoodsReceipt, $match->exception_code);
        $this->assertCount(1, $match->lines);
        $this->assertNull($match->lines->first()->goods_receipt_line_id);
        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_duplicate_supplier_invoice_fails_without_duplicate_invoice_or_match_evidence(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture);

        $this->service->registerAndMatch($payload, $this->actor);
        $before = $this->invoiceEvidenceCounts();

        try {
            $this->service->registerAndMatch($payload, $this->actor);
            $this->fail('Duplicate supplier invoice registration must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertSame($before, $this->invoiceEvidenceCounts());
        $this->assertSame(1, DB::table('vendor_invoices')->where('invoice_number', $payload['invoice_number'])->count());
        $this->assertSame(1, DB::table('three_way_matches')->count());
        $this->assertSame(1, DB::table('three_way_match_lines')->count());
    }

    public function test_reevaluating_same_persisted_invoice_is_idempotent(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $before = $this->invoiceEvidenceCounts();

        $secondMatch = app(ThreeWayMatchingEngine::class)->performMatch($result['invoice']->fresh(['lines']));

        $this->assertSame($result['match']->id, $secondMatch->id);
        $this->assertSame($before, $this->invoiceEvidenceCounts());
    }

    public function test_unauthorized_unresolved_and_disabled_actors_fail_closed(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $disabled = $this->makeUser(false);
        $this->attachActorToProperty($disabled, $this->property);
        $disabled->givePermissionTo(SupplierInvoiceRegistrationService::PERMISSION);

        foreach ([$unauthorized, $disabled] as $actor) {
            $payload = $this->invoicePayload($fixture);
            $before = $this->invoiceEvidenceCounts();

            try {
                $this->service->registerAndMatch($payload, $actor);
                $this->fail('Registration must fail closed for unauthorized or disabled actors.');
            } catch (AuthorizationException) {
                $this->assertSame($before, $this->invoiceEvidenceCounts());
                $this->assertDatabaseMissing('vendor_invoices', [
                    'invoice_number' => $payload['invoice_number'],
                ]);
            }
        }
    }

    public function test_cross_property_vendor_po_and_receiving_relations_fail_closed_without_source_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $otherProperty = $this->makeProperty();
        $otherFixture = $this->makePurchasingFixture($otherProperty);
        $sourceBefore = $this->sourceSnapshot($fixture);

        $cases = [
            $this->invoicePayload($fixture, ['vendor_id' => $otherFixture['vendor_id']]),
            $this->invoicePayload($fixture, ['purchase_order_id' => $otherFixture['purchase_order_id']]),
            $this->invoicePayload($fixture, ['goods_receipt_id' => $otherFixture['goods_receipt_id']], [
                'goods_receipt_line_id' => $otherFixture['goods_receipt_line_id'],
            ]),
        ];

        foreach ($cases as $payload) {
            $before = $this->invoiceEvidenceCounts();

            try {
                $this->service->registerAndMatch($payload, $this->actor);
                $this->fail('Cross-property supplier invoice evidence must fail closed.');
            } catch (DomainException) {
                $this->assertSame($before, $this->invoiceEvidenceCounts());
                $this->assertDatabaseMissing('vendor_invoices', [
                    'invoice_number' => $payload['invoice_number'],
                ]);
            }
        }

        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
    }

    public function test_authorized_actor_resolves_supplier_invoice_exception_with_evidence_preservation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture, [], [
            'quantity' => 8,
            'unit_price' => 12.50,
        ]), $this->actor);
        $invoice = $result['invoice'];
        $match = $result['match'];
        $controlledBefore = $this->controlledSnapshot();
        $sourceBefore = $this->sourceSnapshot($fixture);
        $matchBefore = $this->matchEvidenceSnapshot($match->id);
        $invoiceBusinessBefore = $this->invoiceBusinessSnapshot($invoice->id);

        $resolved = $this->exceptionReviewService->resolveException(
            $invoice->id,
            $this->actor,
            'Quantity variance reviewed against receiving evidence.'
        );

        $this->assertSame($invoice->id, $resolved->id);
        $this->assertSame('REGISTERED', $resolved->status);
        $this->assertSame($this->actor->id, $resolved->exception_resolved_by);
        $this->assertNotNull($resolved->exception_resolved_at);
        $this->assertSame('Quantity variance reviewed against receiving evidence.', $resolved->exception_resolution_reason);
        $this->assertNull($resolved->approved_by);
        $this->assertNull($resolved->rejected_by);
        $this->assertSame($matchBefore, $this->matchEvidenceSnapshot($match->id));
        $this->assertSame($invoiceBusinessBefore, $this->invoiceBusinessSnapshot($invoice->id));
        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
        $this->assertControlledSnapshotUnchanged($controlledBefore);
    }

    public function test_exception_resolution_requires_reason_and_leaves_invoice_unchanged(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture, [], [
            'quantity' => 8,
            'unit_price' => 12.50,
        ]), $this->actor);
        $invoice = $result['invoice'];
        $before = $this->invoiceLifecycleSnapshot($invoice->id);
        $matchBefore = $this->matchEvidenceSnapshot($result['match']->id);
        $controlledBefore = $this->controlledSnapshot();

        try {
            $this->exceptionReviewService->resolveException($invoice->id, $this->actor, '   ');
            $this->fail('Exception resolution without a reason must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('reason', $exception->getMessage());
        }

        $this->assertSame($before, $this->invoiceLifecycleSnapshot($invoice->id));
        $this->assertSame($matchBefore, $this->matchEvidenceSnapshot($result['match']->id));
        $this->assertControlledSnapshotUnchanged($controlledBefore);
    }

    public function test_matched_invoice_can_be_approved_by_authorized_actor_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $invoice = $result['invoice'];
        $controlledBefore = $this->controlledSnapshot();
        $sourceBefore = $this->sourceSnapshot($fixture);
        $matchBefore = $this->matchEvidenceSnapshot($result['match']->id);

        $approved = $this->approvalService->approve($invoice->id, $this->actor);

        $this->assertSame('APPROVED', $approved->status);
        $this->assertSame($this->actor->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertNull($approved->rejected_by);
        $this->assertNull($approved->exception_resolved_by);
        $this->assertSame($matchBefore, $this->matchEvidenceSnapshot($result['match']->id));
        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
        $this->assertControlledSnapshotUnchanged($controlledBefore);
    }

    public function test_matched_invoice_can_be_rejected_by_authorized_actor_with_reason_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $invoice = $result['invoice'];
        $controlledBefore = $this->controlledSnapshot();
        $sourceBefore = $this->sourceSnapshot($fixture);
        $matchBefore = $this->matchEvidenceSnapshot($result['match']->id);

        $rejected = $this->approvalService->reject($invoice->id, $this->actor, 'Supplier invoice rejected by Finance.');

        $this->assertSame('REJECTED', $rejected->status);
        $this->assertSame($this->actor->id, $rejected->rejected_by);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame('Supplier invoice rejected by Finance.', $rejected->rejection_reason);
        $this->assertNull($rejected->approved_by);
        $this->assertSame($matchBefore, $this->matchEvidenceSnapshot($result['match']->id));
        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
        $this->assertControlledSnapshotUnchanged($controlledBefore);
    }

    public function test_unresolved_exception_cannot_be_approved_and_resolved_exception_can_be_approved(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture, [], [
            'unit_price' => 13,
        ]), $this->actor);
        $invoice = $result['invoice'];
        $before = $this->invoiceLifecycleSnapshot($invoice->id);

        try {
            $this->approvalService->approve($invoice->id, $this->actor);
            $this->fail('Unresolved exception invoice approval must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('resolved', $exception->getMessage());
        }

        $this->assertSame($before, $this->invoiceLifecycleSnapshot($invoice->id));

        $this->exceptionReviewService->resolveException($invoice->id, $this->actor, 'Price variance accepted by Finance.');
        $approved = $this->approvalService->approve($invoice->id, $this->actor);

        $this->assertSame('APPROVED', $approved->status);
        $this->assertSame($this->actor->id, $approved->approved_by);
        $this->assertSame('Price variance accepted by Finance.', $approved->exception_resolution_reason);
    }

    public function test_invoice_rejection_requires_reason_and_leaves_invoice_unchanged(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $invoice = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor)['invoice'];
        $before = $this->invoiceLifecycleSnapshot($invoice->id);
        $controlledBefore = $this->controlledSnapshot();

        try {
            $this->approvalService->reject($invoice->id, $this->actor, '  ');
            $this->fail('Invoice rejection without a reason must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('reason', $exception->getMessage());
        }

        $this->assertSame($before, $this->invoiceLifecycleSnapshot($invoice->id));
        $this->assertControlledSnapshotUnchanged($controlledBefore);
    }

    public function test_terminal_invoice_decisions_are_idempotent_and_conflicting_decisions_fail(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $invoice = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor)['invoice'];
        $approved = $this->approvalService->approve($invoice->id, $this->actor);
        $approvedSnapshot = $this->invoiceLifecycleSnapshot($invoice->id);
        $otherActor = $this->makeAuthorizedActor($this->property);

        $repeatApproval = $this->approvalService->approve($invoice->id, $this->actor);

        $this->assertSame($approved->approved_at->toDateTimeString(), $repeatApproval->approved_at->toDateTimeString());
        $this->assertSame($approvedSnapshot, $this->invoiceLifecycleSnapshot($invoice->id));

        foreach ([
            fn () => $this->approvalService->approve($invoice->id, $otherActor),
            fn () => $this->approvalService->reject($invoice->id, $this->actor, 'Changed decision.'),
        ] as $decision) {
            try {
                $decision();
                $this->fail('Conflicting terminal invoice decision must fail.');
            } catch (DomainException) {
                $this->assertSame($approvedSnapshot, $this->invoiceLifecycleSnapshot($invoice->id));
            }
        }

        $secondFixture = $this->makePurchasingFixture($this->property);
        $rejectedInvoice = $this->service->registerAndMatch($this->invoicePayload($secondFixture), $this->actor)['invoice'];
        $rejected = $this->approvalService->reject($rejectedInvoice->id, $this->actor, 'Rejected by Finance.');
        $rejectedSnapshot = $this->invoiceLifecycleSnapshot($rejectedInvoice->id);
        $repeatRejection = $this->approvalService->reject($rejectedInvoice->id, $this->actor, 'Rejected by Finance.');

        $this->assertSame($rejected->rejected_at->toDateTimeString(), $repeatRejection->rejected_at->toDateTimeString());
        $this->assertSame($rejectedSnapshot, $this->invoiceLifecycleSnapshot($rejectedInvoice->id));

        foreach ([
            fn () => $this->approvalService->reject($rejectedInvoice->id, $this->actor, 'Different reason.'),
            fn () => $this->approvalService->reject($rejectedInvoice->id, $otherActor, 'Rejected by Finance.'),
            fn () => $this->approvalService->approve($rejectedInvoice->id, $this->actor),
        ] as $decision) {
            try {
                $decision();
                $this->fail('Conflicting terminal invoice decision must fail.');
            } catch (DomainException) {
                $this->assertSame($rejectedSnapshot, $this->invoiceLifecycleSnapshot($rejectedInvoice->id));
            }
        }
    }

    public function test_exception_resolution_is_idempotent_and_conflicting_repeat_fails(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture, [], [
            'quantity' => 8,
        ]), $this->actor);
        $invoice = $result['invoice'];
        $resolved = $this->exceptionReviewService->resolveException($invoice->id, $this->actor, 'Variance resolved.');
        $resolvedSnapshot = $this->invoiceLifecycleSnapshot($invoice->id);
        $matchBefore = $this->matchEvidenceSnapshot($result['match']->id);
        $otherActor = $this->makeAuthorizedActor($this->property);

        $repeatResolution = $this->exceptionReviewService->resolveException($invoice->id, $this->actor, 'Variance resolved.');

        $this->assertSame($resolved->exception_resolved_at->toDateTimeString(), $repeatResolution->exception_resolved_at->toDateTimeString());
        $this->assertSame($resolvedSnapshot, $this->invoiceLifecycleSnapshot($invoice->id));
        $this->assertSame($matchBefore, $this->matchEvidenceSnapshot($result['match']->id));

        foreach ([
            fn () => $this->exceptionReviewService->resolveException($invoice->id, $this->actor, 'Different resolution.'),
            fn () => $this->exceptionReviewService->resolveException($invoice->id, $otherActor, 'Variance resolved.'),
        ] as $review) {
            try {
                $review();
                $this->fail('Conflicting exception resolution must fail.');
            } catch (DomainException) {
                $this->assertSame($resolvedSnapshot, $this->invoiceLifecycleSnapshot($invoice->id));
                $this->assertSame($matchBefore, $this->matchEvidenceSnapshot($result['match']->id));
            }
        }
    }

    public function test_review_and_decision_authorization_failures_leave_invoice_and_accounting_unchanged(): void
    {
        $exceptionFixture = $this->makePurchasingFixture($this->property);
        $exceptionResult = $this->service->registerAndMatch($this->invoicePayload($exceptionFixture, [], [
            'quantity' => 8,
        ]), $this->actor);
        $matchedFixture = $this->makePurchasingFixture($this->property);
        $matchedInvoice = $this->service->registerAndMatch($this->invoicePayload($matchedFixture), $this->actor)['invoice'];
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $disabled = $this->makeAuthorizedActor($this->property, false);
        $unresolved = $this->makeAuthorizedActor($this->property);
        $unresolved->delete();
        $otherProperty = $this->makeProperty();
        $crossProperty = $this->makeAuthorizedActor($otherProperty);

        foreach ([$unauthorized, $disabled, $unresolved, $crossProperty] as $invalidActor) {
            $exceptionBefore = $this->invoiceLifecycleSnapshot($exceptionResult['invoice']->id);
            $matchedBefore = $this->invoiceLifecycleSnapshot($matchedInvoice->id);
            $controlledBefore = $this->controlledSnapshot();

            foreach ([
                fn () => $this->exceptionReviewService->resolveException($exceptionResult['invoice']->id, $invalidActor, 'Resolution attempt.'),
                fn () => $this->approvalService->approve($matchedInvoice->id, $invalidActor),
                fn () => $this->approvalService->reject($matchedInvoice->id, $invalidActor, 'Rejected attempt.'),
            ] as $action) {
                try {
                    $action();
                    $this->fail('Supplier invoice review and decision actions must fail closed for invalid actors.');
                } catch (AuthorizationException) {
                    $this->assertSame($exceptionBefore, $this->invoiceLifecycleSnapshot($exceptionResult['invoice']->id));
                    $this->assertSame($matchedBefore, $this->invoiceLifecycleSnapshot($matchedInvoice->id));
                    $this->assertControlledSnapshotUnchanged($controlledBefore);
                }
            }
        }
    }

    public function test_approved_matched_invoice_with_posted_grni_source_is_eligible_for_future_allocation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $invoice = $this->approvalService->approve($result['invoice']->id, $this->actor);
        $grni = $this->makePostedGrniEvidence($fixture);
        $controlledBefore = $this->controlledSnapshot();
        $sourceBefore = $this->sourceSnapshot($fixture);
        $matchBefore = $this->matchEvidenceSnapshot($result['match']->id);

        $eligibility = $this->grniEligibilityService->evaluate($invoice->id);
        $repeatEligibility = $this->grniEligibilityService->evaluate($invoice->id);

        $this->assertSame(GrniClearingAllocationEligibilityService::DECISION_ELIGIBLE, $eligibility['decision']);
        $this->assertSame([], $eligibility['blockers']);
        $this->assertSame($invoice->id, $eligibility['invoice_id']);
        $this->assertSame('APPROVED', $eligibility['invoice_status']);
        $this->assertSame(MatchStatusEnum::Matched->value, $eligibility['match_status']);
        $this->assertSame('IDR', $eligibility['source_currency']);
        $this->assertSame([
            'supplier_invoice_id' => $invoice->id,
            'purchase_order_id' => $fixture['purchase_order_id'],
            'receiving_document_id' => $fixture['goods_receipt_id'],
            'grni_candidate_id' => $grni['journal_candidate_id'],
            'posted_journal_entry_id' => $grni['journal_entry_id'],
        ], $eligibility['source_evidence']);

        $this->assertCount(1, $eligibility['lines']);
        $this->assertSame($result['invoice']->lines->first()->id, $eligibility['lines'][0]['supplier_invoice_line_id']);
        $this->assertSame($fixture['purchase_order_line_id'], $eligibility['lines'][0]['purchase_order_line_id']);
        $this->assertSame($fixture['goods_receipt_line_id'], $eligibility['lines'][0]['receiving_line_id']);
        $this->assertSame($grni['inventory_receipt_line_id'], $eligibility['lines'][0]['inventory_receipt_line_id']);
        $this->assertSame($fixture['inventory_item_id'], $eligibility['lines'][0]['inventory_item_id']);
        $this->assertEligibilityResultContainsNoPostingPlan($eligibility);
        $this->assertSame($eligibility, $repeatEligibility);
        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
        $this->assertSame($matchBefore, $this->matchEvidenceSnapshot($result['match']->id));
        $this->assertControlledSnapshotUnchanged($controlledBefore);
    }

    public function test_grni_allocation_eligibility_blocks_non_approved_and_exception_invoices_without_mutation(): void
    {
        $registeredFixture = $this->makePurchasingFixture($this->property);
        $registeredResult = $this->service->registerAndMatch($this->invoicePayload($registeredFixture), $this->actor);
        $this->makePostedGrniEvidence($registeredFixture);

        $rejectedFixture = $this->makePurchasingFixture($this->property);
        $rejectedResult = $this->service->registerAndMatch($this->invoicePayload($rejectedFixture), $this->actor);
        $rejected = $this->approvalService->reject($rejectedResult['invoice']->id, $this->actor, 'Not eligible for allocation.');
        $this->makePostedGrniEvidence($rejectedFixture);

        $exceptionFixture = $this->makePurchasingFixture($this->property);
        $exceptionResult = $this->service->registerAndMatch($this->invoicePayload($exceptionFixture, [], [
            'unit_price' => 13,
        ]), $this->actor);
        $this->exceptionReviewService->resolveException($exceptionResult['invoice']->id, $this->actor, 'Reviewed price exception.');
        $approvedException = $this->approvalService->approve($exceptionResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($exceptionFixture);

        $controlledBefore = $this->controlledSnapshot();

        $registeredEligibility = $this->grniEligibilityService->evaluate($registeredResult['invoice']->id);
        $rejectedEligibility = $this->grniEligibilityService->evaluate($rejected->id);
        $exceptionEligibility = $this->grniEligibilityService->evaluate($approvedException->id);

        $this->assertSame(GrniClearingAllocationEligibilityService::DECISION_BLOCKED, $registeredEligibility['decision']);
        $this->assertContains('invoice_not_approved', $registeredEligibility['blockers']);
        $this->assertSame(GrniClearingAllocationEligibilityService::DECISION_BLOCKED, $rejectedEligibility['decision']);
        $this->assertContains('invoice_not_approved', $rejectedEligibility['blockers']);
        $this->assertSame(GrniClearingAllocationEligibilityService::DECISION_BLOCKED, $exceptionEligibility['decision']);
        $this->assertContains('match_not_matched', $exceptionEligibility['blockers']);
        $this->assertEligibilityResultContainsNoPostingPlan($registeredEligibility);
        $this->assertEligibilityResultContainsNoPostingPlan($rejectedEligibility);
        $this->assertEligibilityResultContainsNoPostingPlan($exceptionEligibility);
        $this->assertControlledSnapshotUnchanged($controlledBefore);
    }

    public function test_grni_allocation_eligibility_blocks_missing_or_unposted_grni_chain_without_mutation(): void
    {
        $missingFixture = $this->makePurchasingFixture($this->property);
        $missingResult = $this->service->registerAndMatch($this->invoicePayload($missingFixture), $this->actor);
        $missingInvoice = $this->approvalService->approve($missingResult['invoice']->id, $this->actor);

        $missingCandidateFixture = $this->makePurchasingFixture($this->property);
        $missingCandidateResult = $this->service->registerAndMatch($this->invoicePayload($missingCandidateFixture), $this->actor);
        $missingCandidateInvoice = $this->approvalService->approve($missingCandidateResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($missingCandidateFixture, [
            'include_candidate' => false,
        ]);

        $missingJournalFixture = $this->makePurchasingFixture($this->property);
        $missingJournalResult = $this->service->registerAndMatch($this->invoicePayload($missingJournalFixture), $this->actor);
        $missingJournalInvoice = $this->approvalService->approve($missingJournalResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($missingJournalFixture, [
            'include_journal' => false,
        ]);

        $draftJournalFixture = $this->makePurchasingFixture($this->property);
        $draftJournalResult = $this->service->registerAndMatch($this->invoicePayload($draftJournalFixture), $this->actor);
        $draftJournalInvoice = $this->approvalService->approve($draftJournalResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($draftJournalFixture, [
            'journal_status' => 'Draft',
            'journal_posted_by' => null,
            'journal_posted_at' => null,
        ]);

        $controlledBefore = $this->controlledSnapshot();

        $missingEligibility = $this->grniEligibilityService->evaluate($missingInvoice->id);
        $missingCandidateEligibility = $this->grniEligibilityService->evaluate($missingCandidateInvoice->id);
        $missingJournalEligibility = $this->grniEligibilityService->evaluate($missingJournalInvoice->id);
        $draftJournalEligibility = $this->grniEligibilityService->evaluate($draftJournalInvoice->id);

        $this->assertContains('missing_posted_inventory_receipt', $missingEligibility['blockers']);
        $this->assertContains('missing_grni_candidate', $missingCandidateEligibility['blockers']);
        $this->assertContains('missing_posted_journal_entry', $missingJournalEligibility['blockers']);
        $this->assertContains('journal_entry_not_posted', $draftJournalEligibility['blockers']);
        $this->assertSame(GrniClearingAllocationEligibilityService::DECISION_BLOCKED, $missingEligibility['decision']);
        $this->assertSame(GrniClearingAllocationEligibilityService::DECISION_BLOCKED, $missingCandidateEligibility['decision']);
        $this->assertSame(GrniClearingAllocationEligibilityService::DECISION_BLOCKED, $missingJournalEligibility['decision']);
        $this->assertSame(GrniClearingAllocationEligibilityService::DECISION_BLOCKED, $draftJournalEligibility['decision']);
        $this->assertControlledSnapshotUnchanged($controlledBefore);
    }

    public function test_grni_allocation_eligibility_blocks_scope_ambiguous_and_unsupported_conditions_without_mutation(): void
    {
        $crossPropertyFixture = $this->makePurchasingFixture($this->property);
        $crossPropertyResult = $this->service->registerAndMatch($this->invoicePayload($crossPropertyFixture), $this->actor);
        $crossPropertyInvoice = $this->approvalService->approve($crossPropertyResult['invoice']->id, $this->actor);
        $otherProperty = $this->makeProperty();
        $this->makePostedGrniEvidence($crossPropertyFixture, [
            'journal_property_id' => $otherProperty->id,
        ]);

        $crossVendorFixture = $this->makePurchasingFixture($this->property);
        $crossVendorResult = $this->service->registerAndMatch($this->invoicePayload($crossVendorFixture), $this->actor);
        $crossVendorInvoice = $this->approvalService->approve($crossVendorResult['invoice']->id, $this->actor);
        DB::table('receiving_documents')
            ->where('id', $crossVendorFixture['goods_receipt_id'])
            ->update(['vendor_id' => $this->makeVendor($this->property, 'ALT-' . $this->sequence++)]);
        $this->makePostedGrniEvidence($crossVendorFixture);

        $ambiguousFixture = $this->makePurchasingFixture($this->property);
        $ambiguousResult = $this->service->registerAndMatch($this->invoicePayload($ambiguousFixture), $this->actor);
        $ambiguousInvoice = $this->approvalService->approve($ambiguousResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($ambiguousFixture);
        $this->makePostedGrniEvidence($ambiguousFixture, [
            'include_candidate' => false,
        ]);

        $taxFixture = $this->makePurchasingFixture($this->property);
        $taxResult = $this->service->registerAndMatch($this->invoicePayload($taxFixture, [
            'tax_amount' => 5,
        ]), $this->actor);
        $taxInvoice = $this->approvalService->approve($taxResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($taxFixture);

        $currencyFixture = $this->makePurchasingFixture($this->property);
        $currencyResult = $this->service->registerAndMatch($this->invoicePayload($currencyFixture), $this->actor);
        $currencyInvoice = $this->approvalService->approve($currencyResult['invoice']->id, $this->actor);
        DB::table('vendor_invoices')
            ->where('id', $currencyInvoice->id)
            ->update(['currency_code' => 'USD']);
        $this->makePostedGrniEvidence($currencyFixture);

        $quantityFixture = $this->makePurchasingFixture($this->property);
        $quantityResult = $this->service->registerAndMatch($this->invoicePayload($quantityFixture), $this->actor);
        $quantityInvoice = $this->approvalService->approve($quantityResult['invoice']->id, $this->actor);
        $quantityGrni = $this->makePostedGrniEvidence($quantityFixture);
        DB::table('inventory_receipt_lines')
            ->where('id', $quantityGrni['inventory_receipt_line_id'])
            ->update(['quantity' => 9]);

        $priceFixture = $this->makePurchasingFixture($this->property);
        $priceResult = $this->service->registerAndMatch($this->invoicePayload($priceFixture), $this->actor);
        $priceInvoice = $this->approvalService->approve($priceResult['invoice']->id, $this->actor);
        $priceGrni = $this->makePostedGrniEvidence($priceFixture);
        DB::table('inventory_receipt_lines')
            ->where('id', $priceGrni['inventory_receipt_line_id'])
            ->update(['unit_cost' => 13]);

        $controlledBefore = $this->controlledSnapshot();

        $cases = [
            [$this->grniEligibilityService->evaluate($crossPropertyInvoice->id), 'journal_entry_source_mismatch'],
            [$this->grniEligibilityService->evaluate($crossVendorInvoice->id), 'receiving_vendor_mismatch'],
            [$this->grniEligibilityService->evaluate($ambiguousInvoice->id), 'ambiguous_grni_source'],
            [$this->grniEligibilityService->evaluate($taxInvoice->id), 'unsupported_tax_condition'],
            [$this->grniEligibilityService->evaluate($currencyInvoice->id), 'unsupported_currency_condition'],
            [$this->grniEligibilityService->evaluate($quantityInvoice->id), 'unsupported_quantity_condition'],
            [$this->grniEligibilityService->evaluate($priceInvoice->id), 'unsupported_price_condition'],
        ];

        foreach ($cases as [$eligibility, $expectedBlocker]) {
            $this->assertSame(GrniClearingAllocationEligibilityService::DECISION_BLOCKED, $eligibility['decision']);
            $this->assertContains($expectedBlocker, $eligibility['blockers']);
            $this->assertEligibilityResultContainsNoPostingPlan($eligibility);
        }

        $this->assertControlledSnapshotUnchanged($controlledBefore);
    }

    public function test_authorized_actor_creates_pending_review_grni_clearing_ap_liability_candidate(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $accounts = $this->makeGrniClearingAccountMappings($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $invoice = $this->approvalService->approve($result['invoice']->id, $this->actor);
        $grni = $this->makePostedGrniEvidence($fixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
        ]);
        $sourceBefore = $this->sourceSnapshot($fixture);
        $matchBefore = $this->matchEvidenceSnapshot($result['match']->id);
        $invoiceBefore = $this->invoiceLifecycleSnapshot($invoice->id);
        $controlledBefore = $this->controlledSnapshot();

        $this->actingAs($this->actor);
        $candidate = $this->grniCandidateService->createForSupplierInvoice($invoice->id);

        $this->assertSame('PENDING_REVIEW', $candidate->status->value);
        $this->assertSame($this->property->id, $candidate->property_id);
        $this->assertSame('SupplierInvoice', $candidate->source_type);
        $this->assertSame($invoice->id, $candidate->source_id);
        $this->assertSame($grni['journal_candidate_id'], $candidate->source_grni_candidate_id);
        $this->assertSame($grni['journal_entry_id'], $candidate->source_grni_journal_entry_id);
        $this->assertSame('SupplierInvoiceGrniClearingApLiability', $candidate->posting_event);
        $this->assertSame($this->actor->id, $candidate->created_by);
        $this->assertNull($candidate->approved_by);
        $this->assertSame('IDR', $candidate->metadata['currency_code']);
        $this->assertSame('125.00', $candidate->metadata['amount']);
        $this->assertSame($invoice->id, $candidate->metadata['supplier_invoice']['id']);
        $this->assertSame($result['invoice']->lines->first()->id, $candidate->metadata['supplier_invoice_line']['id']);
        $this->assertSame($fixture['purchase_order_id'], $candidate->metadata['purchase_order']['id']);
        $this->assertSame($fixture['purchase_order_line_id'], $candidate->metadata['purchase_order']['line_id']);
        $this->assertSame($fixture['goods_receipt_id'], $candidate->metadata['receiving']['document_id']);
        $this->assertSame($fixture['goods_receipt_line_id'], $candidate->metadata['receiving']['line_id']);
        $this->assertSame($grni['journal_candidate_id'], $candidate->metadata['source_grni']['candidate_id']);
        $this->assertSame($grni['journal_entry_id'], $candidate->metadata['source_grni']['journal_entry_id']);
        $this->assertSame($accounts['grni_account_id'], $candidate->metadata['accounts']['grni_liability']['account_id']);
        $this->assertSame($accounts['ap_account_id'], $candidate->metadata['accounts']['ap_liability_control']['account_id']);

        $lines = $candidate->lines->values();
        $this->assertCount(2, $lines);
        $this->assertSame('GRNI_RECEIPT', $lines[0]->operational_identity->value);
        $this->assertSame('DEBIT', $lines[0]->entry_type->value);
        $this->assertEquals('125.0000', $lines[0]->amount);
        $this->assertSame('AP_CONTROL', $lines[1]->operational_identity->value);
        $this->assertSame('CREDIT', $lines[1]->entry_type->value);
        $this->assertEquals('125.0000', $lines[1]->amount);

        $this->assertSame(1, DB::table('journal_candidates')
            ->where('property_id', $this->property->id)
            ->where('source_type', 'SupplierInvoice')
            ->where('source_id', $invoice->id)
            ->where('posting_event', 'SupplierInvoiceGrniClearingApLiability')
            ->count());
        $this->assertSame(0, DB::table('gl_journal_entries')->where('journal_candidate_id', $candidate->id)->count());
        $this->assertCandidateCreationOnlyAddedCandidate($controlledBefore);
        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
        $this->assertSame($matchBefore, $this->matchEvidenceSnapshot($result['match']->id));
        $this->assertSame($invoiceBefore, $this->invoiceLifecycleSnapshot($invoice->id));
    }

    public function test_grni_clearing_ap_liability_candidate_creation_is_idempotent_and_conflict_safe(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $accounts = $this->makeGrniClearingAccountMappings($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $invoice = $this->approvalService->approve($result['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($fixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
        ]);

        $this->actingAs($this->actor);
        $candidate = $this->grniCandidateService->createForSupplierInvoice($invoice->id);
        $snapshot = $this->candidateSnapshot($candidate->id);
        $controlledBeforeRepeat = $this->controlledSnapshot();

        $repeat = $this->grniCandidateService->createForSupplierInvoice($invoice->id);

        $this->assertSame($candidate->id, $repeat->id);
        $this->assertSame($snapshot, $this->candidateSnapshot($candidate->id));
        $this->assertControlledSnapshotUnchanged($controlledBeforeRepeat);

        $newApAccountId = $this->makeAccount($this->property, 'APX-' . $this->sequence++, 'Changed AP Control', 'Liability', 'CurrentLiability', 'Credit');
        DB::table('gl_operational_identity_mappings')
            ->where('id', $accounts['ap_mapping_id'])
            ->update(['account_id' => $newApAccountId]);

        try {
            $this->grniCandidateService->createForSupplierInvoice($invoice->id);
            $this->fail('Conflicting AP liability mapping must fail controlled.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('conflicts', $exception->getMessage());
        }

        $this->assertSame($snapshot, $this->candidateSnapshot($candidate->id));
    }

    public function test_grni_clearing_ap_liability_candidate_creation_fails_closed_for_invalid_sources(): void
    {
        $accounts = $this->makeGrniClearingAccountMappings($this->property);
        $cases = [];

        $registeredFixture = $this->makePurchasingFixture($this->property);
        $registeredResult = $this->service->registerAndMatch($this->invoicePayload($registeredFixture), $this->actor);
        $this->makePostedGrniEvidence($registeredFixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
        ]);
        $cases[] = [$registeredResult['invoice']->id, 'not eligible'];

        $exceptionFixture = $this->makePurchasingFixture($this->property);
        $exceptionResult = $this->service->registerAndMatch($this->invoicePayload($exceptionFixture, [], [
            'unit_price' => 13,
        ]), $this->actor);
        $this->exceptionReviewService->resolveException($exceptionResult['invoice']->id, $this->actor, 'Reviewed price exception.');
        $exceptionInvoice = $this->approvalService->approve($exceptionResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($exceptionFixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
        ]);
        $cases[] = [$exceptionInvoice->id, 'not eligible'];

        $missingJournalFixture = $this->makePurchasingFixture($this->property);
        $missingJournalResult = $this->service->registerAndMatch($this->invoicePayload($missingJournalFixture), $this->actor);
        $missingJournalInvoice = $this->approvalService->approve($missingJournalResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($missingJournalFixture, [
            'include_journal' => false,
        ]);
        $cases[] = [$missingJournalInvoice->id, 'not eligible'];

        $amountFixture = $this->makePurchasingFixture($this->property);
        $amountResult = $this->service->registerAndMatch($this->invoicePayload($amountFixture), $this->actor);
        $amountInvoice = $this->approvalService->approve($amountResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($amountFixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
            'grni_credit_amount' => 124,
        ]);
        $cases[] = [$amountInvoice->id, 'amounts do not match'];

        $taxFixture = $this->makePurchasingFixture($this->property);
        $taxResult = $this->service->registerAndMatch($this->invoicePayload($taxFixture, [
            'tax_amount' => 5,
        ]), $this->actor);
        $taxInvoice = $this->approvalService->approve($taxResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($taxFixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
        ]);
        $cases[] = [$taxInvoice->id, 'not eligible'];

        $ambiguousFixture = $this->makePurchasingFixture($this->property);
        $ambiguousResult = $this->service->registerAndMatch($this->invoicePayload($ambiguousFixture), $this->actor);
        $ambiguousInvoice = $this->approvalService->approve($ambiguousResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($ambiguousFixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
        ]);
        $this->makePostedGrniEvidence($ambiguousFixture, [
            'include_candidate' => false,
        ]);
        $cases[] = [$ambiguousInvoice->id, 'not eligible'];

        $this->actingAs($this->actor);

        foreach ($cases as [$invoiceId, $expectedMessage]) {
            $before = $this->controlledSnapshot();

            try {
                $this->grniCandidateService->createForSupplierInvoice($invoiceId);
                $this->fail('Invalid GRNI clearing AP liability candidate source must fail closed.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString($expectedMessage, $exception->getMessage());
            }

            $this->assertControlledSnapshotUnchanged($before);
        }
    }

    public function test_grni_clearing_ap_liability_candidate_creation_requires_authorized_active_actor_and_accounts(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $accounts = $this->makeGrniClearingAccountMappings($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $invoice = $this->approvalService->approve($result['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($fixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
        ]);
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $disabled = $this->makeAuthorizedActor($this->property, false);
        $unresolved = $this->makeAuthorizedActor($this->property);
        $unresolved->delete();
        $otherProperty = $this->makeProperty();
        $crossProperty = $this->makeAuthorizedActor($otherProperty);

        foreach ([$unauthorized, $disabled, $unresolved, $crossProperty] as $invalidActor) {
            $this->actingAs($invalidActor);
            $before = $this->controlledSnapshot();

            try {
                $this->grniCandidateService->createForSupplierInvoice($invoice->id);
                $this->fail('Unauthorized GRNI clearing AP liability candidate creation must fail closed.');
            } catch (AuthorizationException) {
                $this->assertControlledSnapshotUnchanged($before);
            }
        }

        auth()->logout();
        $before = $this->controlledSnapshot();

        try {
            $this->grniCandidateService->createForSupplierInvoice($invoice->id);
            $this->fail('Missing actor GRNI clearing AP liability candidate creation must fail closed.');
        } catch (AuthorizationException) {
            $this->assertControlledSnapshotUnchanged($before);
        }

        DB::table('gl_operational_identity_mappings')
            ->where('id', $accounts['grni_mapping_id'])
            ->update(['is_active' => false]);

        $this->actingAs($this->actor);
        $before = $this->controlledSnapshot();

        try {
            $this->grniCandidateService->createForSupplierInvoice($invoice->id);
            $this->fail('Inactive GRNI account mapping must fail controlled.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('account evidence is unavailable', $exception->getMessage());
        }

        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_authorized_actor_reviews_grni_clearing_ap_liability_candidate_without_accounting_mutation(): void
    {
        $context = $this->makeApprovedGrniApCandidate();
        $candidate = $context['candidate'];
        $snapshot = $this->candidateSnapshot($candidate->id);
        $controlledBefore = $this->controlledSnapshot();

        $approved = $this->candidateReviewService->approve($candidate->id, $this->actor->id);

        $this->assertSame('APPROVED', $approved->status->value);
        $this->assertSame($this->actor->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame($snapshot['candidate']['source_grni_candidate_id'], $approved->source_grni_candidate_id);
        $this->assertSame($snapshot['candidate']['source_grni_journal_entry_id'], $approved->source_grni_journal_entry_id);
        $this->assertSame($snapshot['candidate']['metadata'], $this->candidateSnapshot($candidate->id)['candidate']['metadata']);

        $this->assertControlledSnapshotUnchangedExcept($controlledBefore, [
            'journal_candidates' => 0,
        ]);

        $reviewedSnapshot = $this->candidateSnapshot($candidate->id);
        $repeat = $this->candidateReviewService->approve($candidate->id, $this->actor->id);
        $this->assertSame($approved->id, $repeat->id);
        $this->assertSame($reviewedSnapshot, $this->candidateSnapshot($candidate->id));
    }

    public function test_authorized_actor_rejects_grni_clearing_ap_liability_candidate_and_blocks_draft_or_posting(): void
    {
        $context = $this->makeApprovedGrniApCandidate();
        $candidate = $context['candidate'];
        $controlledBefore = $this->controlledSnapshot();

        $rejected = $this->candidateReviewService->reject($candidate->id, 'Invoice will be corrected before AP clearing.', $this->actor->id);

        $this->assertSame('REJECTED', $rejected->status->value);
        $this->assertSame($this->actor->id, $rejected->rejected_by);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame('Invoice will be corrected before AP clearing.', $rejected->rejection_reason);
        $this->assertSame($candidate->source_grni_candidate_id, $rejected->source_grni_candidate_id);
        $this->assertSame($candidate->source_grni_journal_entry_id, $rejected->source_grni_journal_entry_id);

        $this->assertControlledSnapshotUnchangedExcept($controlledBefore, [
            'journal_candidates' => 0,
        ]);

        $repeat = $this->candidateReviewService->reject($candidate->id, 'Invoice will be corrected before AP clearing.', $this->actor->id);
        $this->assertSame($rejected->id, $repeat->id);

        try {
            $this->candidateReviewService->approve($candidate->id, $this->actor->id);
            $this->fail('Conflicting review decision must fail controlled.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('already rejected', $exception->getMessage());
        }
    }

    public function test_grni_ap_candidate_review_fails_closed_for_invalid_actor_and_cross_property(): void
    {
        $context = $this->makeApprovedGrniApCandidate();
        $candidate = $context['candidate'];
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $disabled = $this->makeAuthorizedActor($this->property, false);
        $unresolved = $this->makeAuthorizedActor($this->property);
        $unresolved->delete();
        $crossProperty = $this->makeAuthorizedActor($this->makeProperty());

        foreach ([$unauthorized, $disabled, $unresolved, $crossProperty] as $invalidActor) {
            $before = $this->candidateSnapshot($candidate->id);

            try {
                $this->candidateReviewService->approve($candidate->id, $invalidActor->id);
                $this->fail('Invalid candidate reviewer must fail closed.');
            } catch (AuthorizationException) {
                $this->assertSame($before, $this->candidateSnapshot($candidate->id));
            }
        }
    }

    public function test_posted_grni_source_cannot_create_two_grni_ap_candidates(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $accounts = $this->makeGrniClearingAccountMappings($this->property);
        $firstResult = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $firstInvoice = $this->approvalService->approve($firstResult['invoice']->id, $this->actor);
        $secondResult = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $secondInvoice = $this->approvalService->approve($secondResult['invoice']->id, $this->actor);
        $this->makePostedGrniEvidence($fixture, [
            'inventory_account_id' => $accounts['inventory_account_id'],
            'grni_account_id' => $accounts['grni_account_id'],
        ]);

        $this->actingAs($this->actor);
        $firstCandidate = $this->grniCandidateService->createForSupplierInvoice($firstInvoice->id);
        $before = $this->controlledSnapshot();

        try {
            $this->grniCandidateService->createForSupplierInvoice($secondInvoice->id);
            $this->fail('A posted GRNI source must not create a second GRNI/AP clearing candidate.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already has', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('journal_candidates')
            ->where('source_grni_journal_entry_id', $firstCandidate->source_grni_journal_entry_id)
            ->where('posting_event', 'SupplierInvoiceGrniClearingApLiability')
            ->count());
        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_authorized_actor_materializes_approved_grni_ap_candidate_to_single_draft(): void
    {
        $context = $this->makeApprovedGrniApCandidate();
        $candidate = $this->candidateReviewService->approve($context['candidate']->id, $this->actor->id);
        $before = $this->controlledSnapshot();

        $draft = $this->draftMaterializationService->materialize($candidate->id, $this->actor->id);

        $this->assertSame('Draft', $draft->status->value);
        $this->assertSame($this->property->id, $draft->property_id);
        $this->assertSame('Payables', $draft->source_module);
        $this->assertSame('SupplierInvoice', $draft->source_type);
        $this->assertSame($context['invoice']->id, $draft->source_id);
        $this->assertSame($candidate->id, $draft->journal_candidate_id);
        $this->assertSame('SupplierInvoiceGrniClearingApLiability', $draft->posting_event);
        $this->assertNull($draft->posting_date);
        $this->assertNull($draft->posted_by);
        $this->assertNull($draft->posted_at);

        $lines = $draft->lines->values();
        $this->assertCount(2, $lines);
        $this->assertSame($context['accounts']['grni_account_id'], $lines[0]->account_id);
        $this->assertEquals(125.0, (float) $lines[0]->debit_amount);
        $this->assertEquals(0.0, (float) $lines[0]->credit_amount);
        $this->assertSame($context['accounts']['ap_account_id'], $lines[1]->account_id);
        $this->assertEquals(0.0, (float) $lines[1]->debit_amount);
        $this->assertEquals(125.0, (float) $lines[1]->credit_amount);
        $this->assertSame($context['grni']['journal_candidate_id'], $candidate->source_grni_candidate_id);
        $this->assertSame($context['grni']['journal_entry_id'], $candidate->source_grni_journal_entry_id);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'gl_journal_entries' => 1,
            'gl_journal_entry_lines' => 2,
        ]);

        $repeat = $this->draftMaterializationService->materialize($candidate->id, $this->actor->id);
        $this->assertSame($draft->id, $repeat->id);
        $this->assertSame(1, DB::table('gl_journal_entries')->where('journal_candidate_id', $candidate->id)->count());
        $this->assertSame(2, DB::table('gl_journal_entry_lines')->where('journal_entry_id', $draft->id)->count());
    }

    public function test_grni_ap_candidate_materialization_requires_approved_candidate_and_valid_actor_scope(): void
    {
        $context = $this->makeApprovedGrniApCandidate();
        $candidate = $context['candidate'];

        try {
            $this->draftMaterializationService->materialize($candidate->id, $this->actor->id);
            $this->fail('Pending-review GRNI/AP candidate must not materialize.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('APPROVED', $exception->getMessage());
        }

        $rejectedContext = $this->makeApprovedGrniApCandidate($context['accounts']);
        $rejected = $this->candidateReviewService->reject($rejectedContext['candidate']->id, 'Rejected before draft.', $this->actor->id);

        try {
            $this->draftMaterializationService->materialize($rejected->id, $this->actor->id);
            $this->fail('Rejected GRNI/AP candidate must not materialize.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('APPROVED', $exception->getMessage());
        }

        $approved = $this->candidateReviewService->approve($candidate->id, $this->actor->id);
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $disabled = $this->makeAuthorizedActor($this->property, false);
        $unresolved = $this->makeAuthorizedActor($this->property);
        $unresolved->delete();
        $crossProperty = $this->makeAuthorizedActor($this->makeProperty());

        foreach ([$unauthorized, $disabled, $unresolved, $crossProperty] as $invalidActor) {
            $before = $this->controlledSnapshot();

            try {
                $this->draftMaterializationService->materialize($approved->id, $invalidActor->id);
                $this->fail('Invalid materialization actor must fail closed.');
            } catch (AuthorizationException) {
                $this->assertControlledSnapshotUnchanged($before);
            }
        }
    }

    public function test_authorized_actor_finalizes_and_posts_grni_ap_draft_once(): void
    {
        $context = $this->makeApprovedGrniApDraft();
        $draft = $context['draft'];
        $this->openPostingControls($this->property, $draft->transaction_date->toDateString());
        $beforeAuthorization = $this->controlledSnapshot();

        $authorizedDraft = $this->draftFinalizationAuthorizationService->authorize($draft->id, $this->actor->id);

        $this->assertSame('Draft', $authorizedDraft->status->value);
        $this->assertSame($this->actor->id, $authorizedDraft->draft_finalization_authorized_by);
        $this->assertNotNull($authorizedDraft->draft_finalization_authorized_at);
        $this->assertNull($authorizedDraft->posting_date);
        $this->assertNull($authorizedDraft->posted_by);
        $this->assertControlledSnapshotUnchanged($beforeAuthorization);

        $beforePost = $this->controlledSnapshot();
        $posted = $this->controlledPostingService->post($draft->id, $this->actor->id);

        $this->assertSame('Posted', $posted->status->value);
        $this->assertSame($this->actor->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
        $this->assertSame($context['candidate']->id, $posted->journal_candidate_id);
        $this->assertSame('SupplierInvoiceGrniClearingApLiability', $posted->posting_event);
        $this->assertControlledSnapshotUnchangedExcept($beforePost, [
            'gl_ledger_balances' => 2,
        ]);

        $balances = $this->ledgerBalancesFor($this->property, [
            $context['accounts']['grni_account_id'],
            $context['accounts']['ap_account_id'],
        ]);
        $this->assertEquals(125.0, (float) $balances[$context['accounts']['grni_account_id']]->debit_total);
        $this->assertEquals(0.0, (float) $balances[$context['accounts']['grni_account_id']]->credit_total);
        $this->assertEquals(0.0, (float) $balances[$context['accounts']['ap_account_id']]->debit_total);
        $this->assertEquals(125.0, (float) $balances[$context['accounts']['ap_account_id']]->credit_total);

        $afterPost = $this->controlledSnapshot();
        $repeat = $this->controlledPostingService->post($draft->id, $this->actor->id);
        $this->assertSame($posted->id, $repeat->id);
        $this->assertControlledSnapshotUnchanged($afterPost);

        $otherActor = $this->makeAuthorizedActor($this->property);
        try {
            $this->controlledPostingService->post($draft->id, $otherActor->id);
            $this->fail('Conflicting posting replay must fail controlled.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Conflicting', $exception->getMessage());
        }
    }

    public function test_grni_ap_draft_authorization_and_posting_fail_closed_for_invalid_state_actor_and_guards(): void
    {
        $context = $this->makeApprovedGrniApDraft();
        $draft = $context['draft'];
        $this->openPostingControls($this->property, $draft->transaction_date->toDateString());
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $disabled = $this->makeAuthorizedActor($this->property, false);
        $unresolved = $this->makeAuthorizedActor($this->property);
        $unresolved->delete();
        $crossProperty = $this->makeAuthorizedActor($this->makeProperty());

        foreach ([$unauthorized, $disabled, $unresolved, $crossProperty] as $invalidActor) {
            $before = $this->controlledSnapshot();

            try {
                $this->draftFinalizationAuthorizationService->authorize($draft->id, $invalidActor->id);
                $this->fail('Invalid finalization actor must fail closed.');
            } catch (AuthorizationException) {
                $this->assertControlledSnapshotUnchanged($before);
            }
        }

        try {
            $this->controlledPostingService->post($draft->id, $this->actor->id);
            $this->fail('Draft without finalization authorization must not post.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('finalization-authorized', $exception->getMessage());
        }

        $authorized = $this->draftFinalizationAuthorizationService->authorize($draft->id, $this->actor->id);
        $this->closeFinancialPeriod($this->property, $authorized->transaction_date->toDateString());
        $beforeClosedPeriod = $this->controlledSnapshot();

        try {
            $this->controlledPostingService->post($draft->id, $this->actor->id);
            $this->fail('Closed Financial Period must block posting.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('FinancialPeriod', $exception->getMessage());
            $this->assertControlledSnapshotUnchanged($beforeClosedPeriod);
        }

        $this->openPostingControls($this->property, $authorized->transaction_date->toDateString());
        $this->closeBusinessDate($this->property, $authorized->transaction_date->toDateString());
        $beforeClosedBusinessDate = $this->controlledSnapshot();

        try {
            $this->controlledPostingService->post($draft->id, $this->actor->id);
            $this->fail('Closed Business Date must block posting.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('PropertyBusinessDate', $exception->getMessage());
            $this->assertControlledSnapshotUnchanged($beforeClosedBusinessDate);
        }

        $this->openPostingControls($this->property, $authorized->transaction_date->toDateString());
        DB::table('gl_accounts')
            ->where('id', $context['accounts']['ap_account_id'])
            ->update(['is_active' => false]);
        $beforeInactiveAccount = $this->controlledSnapshot();

        try {
            $this->controlledPostingService->post($draft->id, $this->actor->id);
            $this->fail('Inactive AP account must block posting.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('inactive account', $exception->getMessage());
            $this->assertControlledSnapshotUnchanged($beforeInactiveAccount);
        }
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

    private function supplierInvoicePermissions(): array
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
        ];
    }

    private function makeAuthorizedActor(Property $property, bool $active = true): User
    {
        $actor = $this->makeUser($active);
        $this->attachActorToProperty($actor, $property);

        if ($active) {
            $actor->givePermissionTo($this->supplierInvoicePermissions());
        } else {
            $actor->givePermissionTo([
                SupplierInvoiceExceptionReviewService::PERMISSION,
                SupplierInvoiceApprovalService::PERMISSION,
                GrniClearingApLiabilityCandidateService::PERMISSION,
            ]);
        }

        return $actor;
    }

    private function makeProperty(): Property
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $timestamp = now();
        $suffix = $this->sequence++;

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'Supplier Invoice Company ' . $suffix,
            'slug' => 'supplier-invoice-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Supplier Invoice Property ' . $suffix,
            'slug' => 'supplier-invoice-property-' . $suffix,
            'code' => 'SIP' . $suffix,
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
            'name' => 'Supplier Invoice User ' . $suffix,
            'email' => 'supplier-invoice-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => $active,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
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
            'name' => 'Supplier invoice test item',
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
            'description' => 'Supplier invoice test item',
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
            'description' => 'Supplier invoice test item',
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

    private function invoicePayload(array $fixture, array $headerOverrides = [], array $lineOverrides = []): array
    {
        $quantity = (float) ($lineOverrides['quantity'] ?? 10);
        $unitPrice = (float) ($lineOverrides['unit_price'] ?? 12.50);
        $lineTotal = array_key_exists('line_total', $lineOverrides)
            ? (float) $lineOverrides['line_total']
            : round($quantity * $unitPrice, 2);

        $payload = [
            'property_id' => $fixture['property_id'],
            'vendor_id' => $fixture['vendor_id'],
            'purchase_order_id' => $fixture['purchase_order_id'],
            'goods_receipt_id' => $fixture['goods_receipt_id'],
            'invoice_number' => 'SINV-' . $this->sequence++,
            'invoice_date' => '2026-06-30',
            'currency_code' => $fixture['currency_code'],
            'tax_amount' => 0,
            'discount_amount' => 0,
            'remarks' => 'Supplier invoice registration test',
            'lines' => [[
                'purchase_order_line_id' => $fixture['purchase_order_line_id'],
                'goods_receipt_line_id' => $fixture['goods_receipt_line_id'],
                'inventory_item_id' => $fixture['inventory_item_id'],
                'description' => 'Supplier invoice test item',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]],
        ];

        $payload = array_replace($payload, $headerOverrides);
        $payload['lines'][0] = array_replace($payload['lines'][0], $lineOverrides);

        return $payload;
    }

    private function invoiceEvidenceCounts(): array
    {
        return [
            'vendor_invoices' => DB::table('vendor_invoices')->count(),
            'vendor_invoice_lines' => DB::table('vendor_invoice_lines')->count(),
            'three_way_matches' => DB::table('three_way_matches')->count(),
            'three_way_match_lines' => DB::table('three_way_match_lines')->count(),
        ];
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'accounts_payables',
            'payment_vouchers',
            'payment_voucher_lines',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'gl_accounts',
            'gl_operational_identity_mappings',
            'financial_periods',
            'gl_financial_periods',
            'property_business_dates',
            'inventory_transactions',
            'cost_ledger_entries',
            'cost_avco_states',
            'inventory_receipts',
            'inventory_receipt_lines',
            'receiving_documents',
            'receiving_lines',
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

    private function assertCandidateCreationOnlyAddedCandidate(array $before): void
    {
        $after = $this->controlledSnapshot();

        foreach ($before as $table => $count) {
            $expected = match ($table) {
                'journal_candidates' => $count + 1,
                'journal_candidate_lines' => $count + 2,
                default => $count,
            };

            $this->assertSame($expected, $after[$table], $table);
        }
    }

    private function candidateSnapshot(string $candidateId): array
    {
        $candidate = (array) DB::table('journal_candidates')
            ->where('id', $candidateId)
            ->first([
                'property_id',
                'source_type',
                'source_id',
                'source_grni_candidate_id',
                'source_grni_journal_entry_id',
                'posting_event',
                'status',
                'candidate_date',
                'description',
                'created_by',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'created_at',
                'metadata',
            ]);

        $lines = DB::table('journal_candidate_lines')
            ->where('journal_candidate_id', $candidateId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'operational_identity',
                'entry_type',
                'amount',
                'cost_center_id',
                'notes',
            ])
            ->map(fn (object $line): array => (array) $line)
            ->all();

        return [
            'candidate' => $candidate,
            'lines' => $lines,
        ];
    }

    private function makeGrniClearingAccountMappings(Property $property): array
    {
        $inventoryAccountId = $this->makeAccount($property, 'INV-' . $this->sequence++, 'Inventory Control', 'Asset', 'CurrentAsset', 'Debit');
        $grniAccountId = $this->makeAccount($property, 'GRNI-' . $this->sequence++, 'GRNI Receipt Liability', 'Liability', 'CurrentLiability', 'Credit');
        $apAccountId = $this->makeAccount($property, 'AP-' . $this->sequence++, 'AP Control Liability', 'Liability', 'CurrentLiability', 'Credit');

        return [
            'inventory_account_id' => $inventoryAccountId,
            'grni_account_id' => $grniAccountId,
            'ap_account_id' => $apAccountId,
            'inventory_mapping_id' => $this->makeOperationalIdentityMapping($property, 'INVENTORY', $inventoryAccountId),
            'grni_mapping_id' => $this->makeOperationalIdentityMapping($property, 'GRNI_RECEIPT', $grniAccountId),
            'ap_mapping_id' => $this->makeOperationalIdentityMapping($property, 'AP_CONTROL', $apAccountId),
        ];
    }

    private function makeApprovedGrniApCandidate(?array $accounts = null): array
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $accounts ??= $this->makeGrniClearingAccountMappings($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $invoice = $this->approvalService->approve($result['invoice']->id, $this->actor);
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

    private function makeApprovedGrniApDraft(): array
    {
        $context = $this->makeApprovedGrniApCandidate();
        $candidate = $this->candidateReviewService->approve($context['candidate']->id, $this->actor->id);
        $draft = $this->draftMaterializationService->materialize($candidate->id, $this->actor->id);

        return $context + [
            'candidate' => $candidate,
            'draft' => $draft,
        ];
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

    private function closeFinancialPeriod(Property $property, string $date): void
    {
        DB::table('gl_financial_periods')
            ->where('property_id', $property->id)
            ->where('period_year', (int) date('Y', strtotime($date)))
            ->where('period_month', (int) date('m', strtotime($date)))
            ->update([
                'status' => 'Closed',
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function closeBusinessDate(Property $property, string $date): void
    {
        DB::table('property_business_dates')
            ->where('property_id', $property->id)
            ->where('business_date', $date)
            ->update([
                'status' => 'Closed',
                'is_open' => null,
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function ledgerBalancesFor(Property $property, array $accountIds): array
    {
        return DB::table('gl_ledger_balances')
            ->where('property_id', $property->id)
            ->whereIn('account_id', $accountIds)
            ->get()
            ->keyBy('account_id')
            ->all();
    }

    private function makeAccount(
        Property $property,
        string $code,
        string $name,
        string $accountType,
        string $accountCategory,
        string $normalBalance,
        bool $active = true,
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
            'is_cash_equivalent' => false,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $accountId;
    }

    private function makeOperationalIdentityMapping(Property $property, string $identity, string $accountId, bool $active = true): string
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
            'is_active' => $active,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $mappingId;
    }

    private function makePostedGrniEvidence(array $fixture, array $overrides = []): array
    {
        $timestamp = now();
        $receiptId = (string) Str::ulid();
        $receiptLineId = (string) Str::ulid();
        $candidateId = (string) Str::ulid();
        $journalEntryId = (string) Str::ulid();
        $receiptPropertyId = $overrides['receipt_property_id'] ?? $fixture['property_id'];
        $candidatePropertyId = $overrides['candidate_property_id'] ?? $fixture['property_id'];
        $journalPropertyId = $overrides['journal_property_id'] ?? $fixture['property_id'];
        $quantity = $overrides['receipt_quantity'] ?? 10;
        $unitCost = $overrides['receipt_unit_cost'] ?? 12.50;
        $lineTotal = $overrides['receipt_line_total'] ?? 125;

        DB::table('inventory_receipts')->insert([
            'id' => $receiptId,
            'property_id' => $receiptPropertyId,
            'receipt_number' => 'IR-' . $this->sequence++,
            'supplier_name' => 'Vendor GRNI source',
            'external_reference' => $fixture['goods_receipt_id'],
            'receiving_document_id' => $fixture['goods_receipt_id'],
            'status' => $overrides['receipt_status'] ?? 'posted',
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
            'property_id' => $receiptPropertyId,
            'receipt_id' => $receiptId,
            'item_id' => $fixture['inventory_item_id'],
            'location_id' => $fixture['location_id'],
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => $lineTotal,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        if (($overrides['include_candidate'] ?? true) === false) {
            return [
                'inventory_receipt_id' => $receiptId,
                'inventory_receipt_line_id' => $receiptLineId,
                'journal_candidate_id' => null,
                'journal_entry_id' => null,
            ];
        }

        DB::table('journal_candidates')->insert([
            'id' => $candidateId,
            'property_id' => $candidatePropertyId,
            'source_type' => 'InventoryReceipt',
            'source_id' => $receiptId,
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => $overrides['candidate_status'] ?? 'APPROVED',
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

        if (($overrides['include_candidate_lines'] ?? true) !== false) {
            foreach ([
                ['identity' => 'INVENTORY', 'entry_type' => 'DEBIT'],
                ['identity' => 'GRNI_RECEIPT', 'entry_type' => 'CREDIT'],
            ] as $line) {
                DB::table('journal_candidate_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'journal_candidate_id' => $candidateId,
                    'operational_identity' => $line['identity'],
                    'entry_type' => $line['entry_type'],
                    'amount' => 125,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        }

        if (($overrides['include_journal'] ?? true) === false) {
            return [
                'inventory_receipt_id' => $receiptId,
                'inventory_receipt_line_id' => $receiptLineId,
                'journal_candidate_id' => $candidateId,
                'journal_entry_id' => null,
            ];
        }

        $hasJournalLines = isset($overrides['inventory_account_id'], $overrides['grni_account_id']);
        $targetJournalStatus = $overrides['journal_status'] ?? 'Posted';
        $insertJournalAsDraft = $hasJournalLines && $targetJournalStatus === 'Posted';
        $journalPostedBy = array_key_exists('journal_posted_by', $overrides)
            ? $overrides['journal_posted_by']
            : $this->actor->id;
        $journalPostedAt = array_key_exists('journal_posted_at', $overrides)
            ? $overrides['journal_posted_at']
            : $timestamp;

        DB::table('gl_journal_entries')->insert([
            'id' => $journalEntryId,
            'property_id' => $journalPropertyId,
            'transaction_date' => '2026-06-30',
            'posting_date' => '2026-06-30',
            'reference' => $receiptId,
            'description' => 'Posted GRNI accrual source',
            'status' => $insertJournalAsDraft ? 'Draft' : $targetJournalStatus,
            'source_module' => 'Inventory',
            'source_type' => 'InventoryReceipt',
            'source_id' => $receiptId,
            'journal_candidate_id' => $candidateId,
            'posting_event' => 'InventoryReceiptAccrual',
            'draft_finalization_authorized_by' => $this->actor->id,
            'draft_finalization_authorized_at' => $timestamp,
            'posted_by' => $insertJournalAsDraft ? null : $journalPostedBy,
            'posted_at' => $insertJournalAsDraft ? null : $journalPostedAt,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        if ($hasJournalLines) {
            DB::table('gl_journal_entry_lines')->insert([
                [
                    'id' => (string) Str::ulid(),
                    'property_id' => $journalPropertyId,
                    'journal_entry_id' => $journalEntryId,
                    'account_id' => $overrides['inventory_account_id'],
                    'debit_amount' => $overrides['inventory_debit_amount'] ?? 125,
                    'credit_amount' => 0,
                    'memo' => 'Posted inventory receipt debit source',
                    'created_by' => $this->actor->id,
                    'updated_by' => $this->actor->id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'id' => (string) Str::ulid(),
                    'property_id' => $journalPropertyId,
                    'journal_entry_id' => $journalEntryId,
                    'account_id' => $overrides['grni_account_id'],
                    'debit_amount' => 0,
                    'credit_amount' => $overrides['grni_credit_amount'] ?? 125,
                    'memo' => 'Posted GRNI liability credit source',
                    'created_by' => $this->actor->id,
                    'updated_by' => $this->actor->id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ]);

            if ($insertJournalAsDraft) {
                DB::table('gl_journal_entries')
                    ->where('id', $journalEntryId)
                    ->update([
                        'status' => $targetJournalStatus,
                        'posted_by' => $journalPostedBy,
                        'posted_at' => $journalPostedAt,
                        'updated_at' => $timestamp,
                    ]);
            }
        }

        return [
            'inventory_receipt_id' => $receiptId,
            'inventory_receipt_line_id' => $receiptLineId,
            'journal_candidate_id' => $candidateId,
            'journal_entry_id' => $journalEntryId,
        ];
    }

    private function assertEligibilityResultContainsNoPostingPlan(array $result): void
    {
        foreach ([
            'posting_date',
            'journal_entry_lines',
            'debit_instruction',
            'credit_instruction',
            'account_mapping',
            'ap_liability_amount',
            'grni_clearing_amount',
            'allocation_amount',
            'allocation_reservation',
            'outstanding_balance',
        ] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $result);
        }
    }

    private function invoiceLifecycleSnapshot(string $invoiceId): array
    {
        return (array) DB::table('vendor_invoices')
            ->where('id', $invoiceId)
            ->first([
                'status',
                'exception_resolved_by',
                'exception_resolved_at',
                'exception_resolution_reason',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'updated_by',
            ]);
    }

    private function invoiceBusinessSnapshot(string $invoiceId): array
    {
        return (array) DB::table('vendor_invoices')
            ->where('id', $invoiceId)
            ->first([
                'property_id',
                'vendor_id',
                'purchase_order_id',
                'goods_receipt_id',
                'invoice_number',
                'invoice_date',
                'due_date',
                'currency_code',
                'subtotal',
                'tax_amount',
                'discount_amount',
                'grand_total',
                'remarks',
                'created_by',
            ]);
    }

    private function matchEvidenceSnapshot(string $matchId): array
    {
        $match = (array) DB::table('three_way_matches')
            ->where('id', $matchId)
            ->first([
                'property_id',
                'vendor_invoice_id',
                'purchase_order_id',
                'goods_receipt_id',
                'status',
                'exception_code',
                'total_quantity_variance',
                'total_price_variance',
                'total_amount_variance',
                'remarks',
                'created_by',
                'updated_by',
            ]);

        $lines = DB::table('three_way_match_lines')
            ->where('three_way_match_id', $matchId)
            ->orderBy('id')
            ->get([
                'vendor_invoice_line_id',
                'purchase_order_line_id',
                'goods_receipt_line_id',
                'inventory_item_id',
                'po_quantity',
                'po_price',
                'grn_quantity',
                'invoice_quantity',
                'invoice_price',
                'quantity_variance',
                'price_variance',
                'amount_variance',
                'created_by',
                'updated_by',
            ])
            ->map(fn (object $line): array => (array) $line)
            ->all();

        return [
            'match' => $match,
            'lines' => $lines,
        ];
    }

    private function sourceSnapshot(array $fixture): array
    {
        return [
            'purchase_order' => (array) DB::table('purchase_orders')
                ->where('id', $fixture['purchase_order_id'])
                ->first(['vendor_id', 'currency_code', 'total_amount', 'received_total', 'status']),
            'purchase_order_line' => (array) DB::table('purchase_order_lines')
                ->where('id', $fixture['purchase_order_line_id'])
                ->first(['ordered_quantity', 'received_quantity', 'invoiced_quantity', 'unit_cost', 'line_total']),
            'goods_receipt' => (array) DB::table('receiving_documents')
                ->where('id', $fixture['goods_receipt_id'])
                ->first(['purchase_order_id', 'vendor_id', 'status']),
            'goods_receipt_line' => (array) DB::table('receiving_lines')
                ->where('id', $fixture['goods_receipt_line_id'])
                ->first(['purchase_order_line_id', 'received_quantity', 'unit_cost', 'line_total']),
        ];
    }
}
