<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
use Modules\Finance\CostControl\Services\CostDeliveryCutoverService;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryCutoverRequest;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use RuntimeException;
use Tests\PostgresTestCase;

final class CostDeliveryCutoverServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_virgin_group_activates_atomically_and_exact_request_reuse_is_idempotent(): void
    {
        [$request, $ownershipId] = $this->fixture();
        $service = app(CostDeliveryCutoverService::class);

        try {
            $first = $service->activateGroup($request);
        } catch (RuntimeException $exception) {
            $this->fail($exception->getPrevious()?->getMessage() ?? $exception->getMessage());
        }
        $second = $service->activateGroup($request);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('cost_delivery_cutovers', 1);
        $this->assertDatabaseCount('cost_delivery_cutover_scopes', 1);
        $this->assertDatabaseCount('cost_delivery_cutover_attempts', 1);
        $this->assertDatabaseHas('cost_delivery_cutover_scopes', [
            'cutover_id' => $first->id,
            'last_synchronously_owned_sequence' => 0,
            'first_deferred_owned_sequence' => 1,
            'cost_avco_last_valuation_sequence' => null,
        ]);
        $this->assertDatabaseHas('cost_delivery_mode_ownerships', [
            'id' => $ownershipId,
            'delivery_mode' => 'DEFERRED',
            'ownership_version' => 2,
            'activated_cutover_id' => $first->id,
        ]);
    }

    public function test_sequence_divergence_rolls_back_activation_then_appends_one_blocked_attempt(): void
    {
        [$request] = $this->fixture();
        $scope = DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $request->enrollmentGroupId)->first();
        DB::table('inventory_valuation_sequences')->insert([
            'id' => (string) Str::ulid(), 'property_id' => $request->propertyId,
            'location_id' => $scope->location_id, 'item_id' => $request->itemId,
            'last_sequence' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            app(CostDeliveryCutoverService::class)->activateGroup($request);
            $this->fail('Sequence divergence must block cutover.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE') {
                $this->fail($exception->getPrevious()?->getMessage() ?? $exception->getMessage());
            }
            $this->assertSame('CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE', $exception->getMessage());
        }

        $this->assertDatabaseCount('cost_delivery_cutovers', 0);
        $this->assertDatabaseCount('cost_delivery_cutover_scopes', 0);
        $this->assertDatabaseHas('cost_delivery_cutover_attempts', [
            'request_id' => $request->requestId,
            'outcome' => 'CUTOVER_BLOCKED',
            'reason_code' => 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE',
            'cutover_id' => null,
        ]);
        $this->assertDatabaseHas('cost_delivery_mode_ownerships', [
            'property_id' => $request->propertyId,
            'item_id' => $request->itemId,
            'delivery_mode' => 'SYNCHRONOUS',
            'ownership_version' => 1,
            'activated_cutover_id' => null,
        ]);
    }

    public function test_pilot_approval_identity_must_match_the_request(): void
    {
        [$request] = $this->fixture();
        $request = $this->copyRequest($request, [
            'requestId' => (string) Str::ulid(),
            'ownerApprovalReference' => 'OWNER-P01F-WRONG',
        ]);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_PILOT_MISMATCH');
    }

    public function test_boundary_must_be_the_first_day_of_the_open_target_period(): void
    {
        [$request] = $this->fixture();
        $request = $this->copyRequest($request, [
            'requestId' => (string) Str::ulid(),
            'boundaryBusinessDate' => '2026-09-02',
        ]);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_BOUNDARY_NOT_PERIOD_START');
    }

    public function test_reopened_target_period_is_rejected(): void
    {
        [$request] = $this->fixture();
        DB::table('gl_financial_periods')->where('id', $request->targetFinancialPeriodId)->update([
            'status' => 'Reopened',
        ]);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_REOPENED_PERIOD');
    }

    public function test_in_flight_document_blocks_cutover(): void
    {
        [$request] = $this->fixture();
        $this->insertReceiptDocument($request, 'draft');

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_IN_FLIGHT_DOCUMENT');
    }

    public function test_terminal_document_without_exact_posting_evidence_blocks_cutover(): void
    {
        [$request] = $this->fixture();
        $this->insertReceiptDocument($request, 'posted');

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_TERMINAL_POSTING_EVIDENCE_GAP');
    }

    public function test_target_period_source_blocks_cutover_before_activation(): void
    {
        [$request, $ownershipId] = $this->fixture();
        $this->insertSource($request, $ownershipId, '2026-09-01', $request->targetFinancialPeriodId);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_TARGET_PERIOD_SOURCE_EXISTS');
    }

    public function test_unclassified_historical_source_blocks_cutover(): void
    {
        [$request, $ownershipId] = $this->fixture();
        $priorPeriodId = (string) FinancialPeriod::where('property_id', $request->propertyId)
            ->where('period_year', 2026)->where('period_month', 8)->value('id');
        $this->insertSource($request, $ownershipId, '2026-08-31', $priorPeriodId);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_HISTORICAL_EVIDENCE_UNCLASSIFIED');
    }

    public function test_blocked_request_replay_returns_the_same_durable_outcome_without_duplicate_attempt(): void
    {
        [$request] = $this->fixture();
        $request = $this->copyRequest($request, ['boundaryBusinessDate' => '2026-09-02']);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_BOUNDARY_NOT_PERIOD_START');
        $this->assertBlocked($request, 'CUTOVER_BLOCKED_BOUNDARY_NOT_PERIOD_START');
        $this->assertDatabaseCount('cost_delivery_cutover_attempts', 1);
    }

    public function test_conflicting_reuse_of_an_activated_request_id_fails_closed(): void
    {
        [$request] = $this->fixture();
        app(CostDeliveryCutoverService::class)->activateGroup($request);
        $conflict = $this->copyRequest($request, ['boundaryBusinessDate' => '2026-09-02']);

        try {
            app(CostDeliveryCutoverService::class)->activateGroup($conflict);
            $this->fail('Conflicting request-id reuse must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('CUTOVER_REQUEST_ID_CONFLICT', $exception->getMessage());
        }
        $this->assertDatabaseCount('cost_delivery_cutovers', 1);
        $this->assertDatabaseCount('cost_delivery_cutover_attempts', 1);
    }

    /** @return array{CostDeliveryCutoverRequest,string} */
    private function fixture(): array
    {
        $property = Property::where('currency', 'USD')->firstOrFail();
        $requester = User::firstOrFail();
        $approver = User::whereKeyNot($requester->id)->firstOrFail();
        $category = InventoryCategory::firstOrCreate([
            'property_id' => $property->id, 'name' => 'P01F Cutover '.Str::random(6),
        ]);
        $item = InventoryItem::create([
            'property_id' => $property->id, 'category_id' => $category->id,
            'sku' => 'P01F-'.Str::random(10), 'name' => 'P01F Cutover Item',
            'inventory_type' => 'goods', 'weighted_average_cost' => 0, 'is_active' => true,
        ]);
        $location = InventoryLocation::create([
            'property_id' => $property->id, 'name' => 'P01F '.Str::random(8), 'type' => 'internal',
        ]);
        $prior = FinancialPeriod::updateOrCreate(
            ['property_id' => $property->id, 'period_year' => 2026, 'period_month' => 8],
            ['status' => FinancialPeriodStatusEnum::Closed, 'closed_at' => now(), 'closed_by' => $approver->id],
        );
        $target = FinancialPeriod::updateOrCreate(
            ['property_id' => $property->id, 'period_year' => 2026, 'period_month' => 9],
            ['status' => FinancialPeriodStatusEnum::Open, 'opened_at' => now(), 'opened_by' => $requester->id],
        );
        DB::table('property_business_dates')->where('property_id', $property->id)->update([
            'status' => 'Closed', 'is_open' => false, 'closed_at' => now(), 'closed_by' => $approver->id,
        ]);
        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $property->id, 'business_date' => '2026-09-01'],
            ['timezone_snapshot' => 'UTC', 'status' => 'Open', 'is_open' => true,
                'opened_by' => $requester->id, 'opened_at' => now(), 'closed_by' => null, 'closed_at' => null],
        );

        $repo = app(CostAuthorityEnrollmentRepository::class);
        $group = $repo->createDraft(
            ['property_id' => $property->id, 'item_id' => $item->id],
            [[
                'location_id' => $location->id,
                'valuation_scope' => "property:{$property->id}:location:{$location->id}:item:{$item->id}",
                'opening_quantity' => '0.0000', 'opening_carrying_value' => '0.0000',
                'currency_code' => 'USD', 'business_date' => '2026-08-31',
                'financial_period_id' => $prior->id, 'source_reference' => 'P01F-TEST',
                'evidence_timestamp' => now(),
            ]],
        );
        DB::transaction(fn () => $repo->approve($group->id, $approver->id, now()));
        app(CostAuthorityEnrollmentBaselineSeedService::class)->seedApprovedGroup($group->id, $requester->id);
        $ownership = app(CostAuthorityEnrollmentActivationService::class)->activate($group->id, $requester->id);
        CostDeliveryPilotProperty::create([
            'pilot_slot' => 1, 'property_id' => $property->id,
            'owner_approval_reference' => 'OWNER-P01F', 'authorized_by' => $approver->id,
            'authorized_at' => now(),
        ]);

        return [new CostDeliveryCutoverRequest(
            requestId: (string) Str::ulid(), propertyId: $property->id, itemId: $item->id,
            enrollmentGroupId: $group->id, targetFinancialPeriodId: $target->id,
            boundaryBusinessDate: '2026-09-01', requestedBy: $requester->id,
            approvedBy: $approver->id, ownerApprovalReference: 'OWNER-P01F',
        ), $ownership->id];
    }

    private function copyRequest(CostDeliveryCutoverRequest $request, array $changes): CostDeliveryCutoverRequest
    {
        return new CostDeliveryCutoverRequest(...array_merge([
            'requestId' => $request->requestId,
            'propertyId' => $request->propertyId,
            'itemId' => $request->itemId,
            'enrollmentGroupId' => $request->enrollmentGroupId,
            'targetFinancialPeriodId' => $request->targetFinancialPeriodId,
            'boundaryBusinessDate' => $request->boundaryBusinessDate,
            'requestedBy' => $request->requestedBy,
            'approvedBy' => $request->approvedBy,
            'ownerApprovalReference' => $request->ownerApprovalReference,
        ], $changes));
    }

    private function assertBlocked(CostDeliveryCutoverRequest $request, string $reason): void
    {
        try {
            app(CostDeliveryCutoverService::class)->activateGroup($request);
            $this->fail("Expected cutover block {$reason}.");
        } catch (RuntimeException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }
        $this->assertDatabaseCount('cost_delivery_cutovers', 0);
        $this->assertDatabaseHas('cost_delivery_cutover_attempts', [
            'request_id' => $request->requestId,
            'outcome' => 'CUTOVER_BLOCKED',
            'reason_code' => $reason,
        ]);
    }

    private function insertReceiptDocument(CostDeliveryCutoverRequest $request, string $status): void
    {
        $locationId = DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $request->enrollmentGroupId)->value('location_id');
        $receiptId = (string) Str::ulid();
        DB::table('inventory_receipts')->insert([
            'id' => $receiptId,
            'property_id' => $request->propertyId,
            'receipt_number' => 'P01F-PREFLIGHT-'.Str::random(8),
            'status' => $status,
            'created_by' => $request->requestedBy,
            'posted_by' => $status === 'posted' ? $request->requestedBy : null,
            'posted_at' => $status === 'posted' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_receipt_lines')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $request->propertyId,
            'receipt_id' => $receiptId,
            'item_id' => $request->itemId,
            'location_id' => $locationId,
            'quantity' => '1.0000',
            'unit_cost' => '10.00',
            'line_total' => '10.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSource(
        CostDeliveryCutoverRequest $request,
        string $ownershipId,
        string $businessDate,
        string $periodId,
    ): InventoryTransaction {
        $locationId = DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $request->enrollmentGroupId)->value('location_id');
        $documentId = (string) Str::ulid();

        return InventoryTransaction::create([
            'property_id' => $request->propertyId,
            'item_id' => $request->itemId,
            'location_id' => $locationId,
            'currency_code' => 'USD',
            'financial_period_id' => $periodId,
            'valuation_scope' => "property:{$request->propertyId}:location:{$locationId}:item:{$request->itemId}",
            'valuation_sequence' => 1,
            'valuation_approval_status' => 'approved',
            'valuation_approval_reference' => "inventory_receipt:{$documentId}:posted",
            'cost_delivery_mode' => 'SYNCHRONOUS',
            'cost_delivery_ownership_id' => $ownershipId,
            'cost_delivery_ownership_version' => 1,
            'cost_delivery_cutover_id' => null,
            'business_date' => $businessDate,
            'occurred_at' => $businessDate.' 10:00:00',
            'source_document_type' => 'inventory_receipt',
            'source_document_id' => $documentId,
            'source_line_type' => 'inventory_receipt_line',
            'source_line_id' => (string) Str::ulid(),
            'movement_role' => 'purchase_receipt',
            'idempotency_key' => 'p01f-preflight-'.Str::random(12),
            'transaction_type' => 'purchase_receipt',
            'quantity_before' => '0.0000',
            'quantity_change' => '1.0000',
            'quantity_after' => '1.0000',
            'unit_cost' => '10.0000',
            'total_cost' => '10.0000',
            'posted_by' => $request->requestedBy,
            'posted_at' => $businessDate.' 10:00:00',
        ]);
    }
}
