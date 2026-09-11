<?php

namespace Tests\Postgres\Finance\CostControl;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Repositories\CostDeliveryCutoverPreflightRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use Modules\Finance\CostControl\Services\CostDeliveryCutoverPreflightService;
use Modules\Finance\CostControl\Services\CostDeliveryPreCutoverVerificationService;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryCutoverRequest;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use RuntimeException;
use Tests\Postgres\Finance\CostControl\Support\CostDeliveryCutoverFixture;
use Tests\PostgresTestCase;

final class CostDeliveryPreCutoverVerificationServiceTest extends PostgresTestCase
{
    use CostDeliveryCutoverFixture;
    use RefreshDatabase;

    protected $seed = true;

    public function test_valid_true_virgin_request_passes_with_exact_zero_one_boundary(): void
    {
        [$request] = $this->makeCutoverFixture();

        $proof = $this->verify($request);

        $this->assertCount(1, $proof['scopes']);
        $this->assertSame('NO_PRIOR_APPLIED_VALUATION_SEQUENCE', $proof['scopes'][0]['sequence_state_classification']);
        $this->assertSame(0, $proof['scopes'][0]['last_synchronously_owned_sequence']);
        $this->assertSame(1, $proof['scopes'][0]['first_deferred_owned_sequence']);
        $this->assertNull($proof['scopes'][0]['cost_avco_last_valuation_sequence']);
    }

    public function test_valid_non_virgin_exact_allocator_and_avco_equality_passes(): void
    {
        [$request] = $this->makeCutoverFixture();
        $scope = $this->scope($request);
        DB::table('cost_avco_states')->where('enrollment_scope_snapshot_id', $scope->id)->update([
            'last_valuation_sequence' => 7,
            'updated_at' => now(),
        ]);
        DB::table('inventory_valuation_sequences')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $request->propertyId,
            'location_id' => $scope->location_id,
            'item_id' => $request->itemId,
            'last_sequence' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $proof = $this->verify($request);

        $this->assertSame('PRIOR_APPLIED_VALUATION_SEQUENCE', $proof['scopes'][0]['sequence_state_classification']);
        $this->assertSame(7, $proof['scopes'][0]['last_synchronously_owned_sequence']);
        $this->assertSame(8, $proof['scopes'][0]['first_deferred_owned_sequence']);
        $this->assertSame(7, $proof['scopes'][0]['cost_avco_last_valuation_sequence']);
    }

    public function test_pilot_mismatch_preserves_canonical_reason(): void
    {
        [$request] = $this->makeCutoverFixture();

        $this->assertBlocked(
            $this->copyRequest($request, ['ownerApprovalReference' => 'OWNER-P02A-WRONG']),
            'CUTOVER_BLOCKED_PILOT_MISMATCH',
        );
    }

    public function test_missing_ownership_fails_closed(): void
    {
        [$request] = $this->makeCutoverFixture();

        $this->assertBlocked(
            $this->copyRequest($request, ['itemId' => (string) Str::ulid()]),
            'CUTOVER_BLOCKED_OWNERSHIP_MISSING',
        );
    }

    public function test_non_synchronous_ownership_fails_closed(): void
    {
        [$request, $ownershipId] = $this->makeCutoverFixture();
        $this->withoutTriggers(['cost_delivery_mode_ownerships'], function () use ($ownershipId): void {
            DB::table('cost_delivery_mode_ownerships')->where('id', $ownershipId)->update([
                'enrollment_group_id' => (string) Str::ulid(),
            ]);
        });

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_OWNERSHIP_NOT_SYNCHRONOUS');
    }

    public function test_target_period_must_be_open(): void
    {
        [$request] = $this->makeCutoverFixture();
        DB::table('gl_financial_periods')->where('id', $request->targetFinancialPeriodId)->update([
            'status' => 'Closed',
        ]);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_TARGET_PERIOD_NOT_OPEN');
    }

    public function test_reopened_target_period_is_rejected(): void
    {
        [$request] = $this->makeCutoverFixture();
        DB::table('gl_financial_periods')->where('id', $request->targetFinancialPeriodId)->update([
            'status' => 'Reopened',
        ]);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_REOPENED_PERIOD');
    }

    public function test_boundary_must_be_first_calendar_day_of_target_period(): void
    {
        [$request] = $this->makeCutoverFixture();

        $this->assertBlocked(
            $this->copyRequest($request, ['boundaryBusinessDate' => '2026-09-02']),
            'CUTOVER_BLOCKED_BOUNDARY_NOT_PERIOD_START',
        );
    }

    public function test_immediately_prior_period_must_be_closed(): void
    {
        [$request] = $this->makeCutoverFixture();
        DB::table('gl_financial_periods')->where('property_id', $request->propertyId)
            ->where('period_year', 2026)->where('period_month', 8)->update(['status' => 'Closing']);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_PRIOR_PERIOD_NOT_CLOSED');
    }

    public function test_business_date_must_be_exact_open_boundary(): void
    {
        [$request] = $this->makeCutoverFixture();
        $this->withoutTriggers(['property_business_dates'], function () use ($request): void {
            DB::table('property_business_dates')->where('property_id', $request->propertyId)
                ->whereDate('business_date', $request->boundaryBusinessDate)
                ->update(['status' => 'Closed', 'is_open' => null]);
        });

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_BUSINESS_DATE_NOT_EXACT_OPEN_BOUNDARY');
    }

    public function test_target_period_source_blocks_verification(): void
    {
        [$request, $ownershipId] = $this->makeCutoverFixture();
        $this->insertSource($request, $ownershipId, '2026-09-01', $request->targetFinancialPeriodId);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_TARGET_PERIOD_SOURCE_EXISTS');
    }

    public function test_in_flight_document_blocks_verification(): void
    {
        [$request] = $this->makeCutoverFixture();
        $this->insertReceiptDocument($request, 'draft');

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_IN_FLIGHT_DOCUMENT');
    }

    public function test_terminal_document_posting_evidence_gap_blocks_verification(): void
    {
        [$request] = $this->makeCutoverFixture();
        $this->insertReceiptDocument($request, 'posted');

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_TERMINAL_POSTING_EVIDENCE_GAP');
    }

    public function test_unclassified_historical_evidence_blocks_verification(): void
    {
        [$request, $ownershipId] = $this->makeCutoverFixture();
        $priorPeriodId = (string) FinancialPeriod::where('property_id', $request->propertyId)
            ->where('period_year', 2026)->where('period_month', 8)->value('id');
        $this->insertSource($request, $ownershipId, '2026-08-31', $priorPeriodId);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_HISTORICAL_EVIDENCE_UNCLASSIFIED');
    }

    public function test_unresolved_deferred_disposition_blocks_verification(): void
    {
        [$request] = $this->makeCutoverFixture();
        $scope = $this->scope($request);
        $this->withoutTriggers(['cost_delivery_outbox_dispositions'], function () use ($request, $scope): void {
            DB::table('cost_delivery_outbox_dispositions')->insert([
                'id' => (string) Str::ulid(),
                'outbox_message_id' => (string) Str::ulid(),
                'source_inventory_transaction_id' => (string) Str::ulid(),
                'property_id' => $request->propertyId,
                'location_id' => $scope->location_id,
                'item_id' => $request->itemId,
                'valuation_scope' => $scope->valuation_scope,
                'valuation_sequence' => 1,
                'classification' => 'DEFERRED_OWNED_AFTER_CUTOVER',
                'processing_state' => 'PENDING',
                'cost_delivery_ownership_id' => (string) Str::ulid(),
                'cost_delivery_ownership_version' => 2,
                'cost_delivery_cutover_id' => (string) Str::ulid(),
                'classified_by' => $request->approvedBy,
                'classification_provenance' => 'DEFERRED_SOURCE_CUTOVER_WATERMARK',
                'classified_at' => now(),
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_UNRESOLVED_DEFERRED_DISPOSITION');
    }

    public function test_missing_schema_control_blocks_verification(): void
    {
        [$request] = $this->makeCutoverFixture();
        DB::statement('DROP INDEX idx_inventory_transactions_reversal_limit');

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_SCHEMA_CONTROLS_MISSING');
    }

    public function test_sequence_divergence_blocks_verification(): void
    {
        [$request] = $this->makeCutoverFixture();
        $scope = $this->scope($request);
        DB::table('inventory_valuation_sequences')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $request->propertyId,
            'location_id' => $scope->location_id,
            'item_id' => $request->itemId,
            'last_sequence' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertBlocked($request, 'CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE');
    }

    public function test_successful_verification_is_observational_only(): void
    {
        [$request] = $this->makeCutoverFixture();
        $tables = [
            'cost_delivery_cutovers',
            'cost_delivery_cutover_scopes',
            'cost_delivery_cutover_attempts',
            'cost_delivery_mode_ownerships',
            'inventory_transactions',
            'cost_ledger_entries',
            'outbox_messages',
            'cost_delivery_outbox_dispositions',
            'cost_avco_states',
            'inventory_valuation_sequences',
            'gl_financial_periods',
            'property_business_dates',
        ];
        $before = $this->fingerprints($tables);

        $this->verify($request);

        $this->assertSame($before, $this->fingerprints($tables));
        $this->assertDatabaseCount('cost_delivery_cutovers', 0);
        $this->assertDatabaseCount('cost_delivery_cutover_scopes', 0);
        $this->assertDatabaseCount('cost_delivery_cutover_attempts', 0);
        $this->assertDatabaseHas('cost_delivery_mode_ownerships', [
            'property_id' => $request->propertyId,
            'item_id' => $request->itemId,
            'delivery_mode' => 'SYNCHRONOUS',
            'ownership_version' => 1,
            'activated_cutover_id' => null,
        ]);
    }

    public function test_failure_reason_is_identical_to_direct_canonical_preflight(): void
    {
        [$request] = $this->makeCutoverFixture();
        $request = $this->copyRequest($request, ['ownerApprovalReference' => 'OWNER-P02A-PARITY']);

        $verificationReason = $this->captureReason(fn () => $this->verify($request));
        $canonicalReason = $this->captureReason(function () use ($request): void {
            DB::transaction(function () use ($request): void {
                $preflightRepository = app(CostDeliveryCutoverPreflightRepository::class);
                $pilots = $preflightRepository->lockPilotRows();
                $ownership = app(CostDeliveryModeOwnershipRepository::class)
                    ->findForUpdateByPropertyItem($request->propertyId, $request->itemId);
                app(CostDeliveryCutoverPreflightService::class)->prove($request, $ownership, $pilots);
            });
        });

        $this->assertSame('CUTOVER_BLOCKED_PILOT_MISMATCH', $verificationReason);
        $this->assertSame($canonicalReason, $verificationReason);
    }

    /** @return array{period:object,scopes:array<int,array<string,mixed>>} */
    private function verify(CostDeliveryCutoverRequest $request): array
    {
        return app(CostDeliveryPreCutoverVerificationService::class)->verify($request);
    }

    private function assertBlocked(CostDeliveryCutoverRequest $request, string $reason): void
    {
        $this->assertSame($reason, $this->captureReason(fn () => $this->verify($request)));
        $this->assertDatabaseCount('cost_delivery_cutovers', 0);
        $this->assertDatabaseCount('cost_delivery_cutover_scopes', 0);
        $this->assertDatabaseCount('cost_delivery_cutover_attempts', 0);
    }

    private function captureReason(callable $operation): string
    {
        try {
            $operation();
            $this->fail('Expected verification to fail closed.');
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
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

    private function scope(CostDeliveryCutoverRequest $request): object
    {
        return DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $request->enrollmentGroupId)->firstOrFail();
    }

    private function insertReceiptDocument(CostDeliveryCutoverRequest $request, string $status): void
    {
        $scope = $this->scope($request);
        $receiptId = (string) Str::ulid();
        DB::table('inventory_receipts')->insert([
            'id' => $receiptId,
            'property_id' => $request->propertyId,
            'receipt_number' => 'P02A-PREFLIGHT-'.Str::random(8),
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
            'location_id' => $scope->location_id,
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
        $scope = $this->scope($request);
        $documentId = (string) Str::ulid();

        return InventoryTransaction::create([
            'property_id' => $request->propertyId,
            'item_id' => $request->itemId,
            'location_id' => $scope->location_id,
            'currency_code' => 'USD',
            'financial_period_id' => $periodId,
            'valuation_scope' => $scope->valuation_scope,
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
            'idempotency_key' => 'p02a-preflight-'.Str::random(12),
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

    /** @param list<string> $tables */
    private function fingerprints(array $tables): array
    {
        $fingerprints = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
            $fingerprints[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $fingerprints;
    }

    /** @param list<string> $tables */
    private function withoutTriggers(array $tables, Closure $callback): void
    {
        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER ALL");
        }
        try {
            $callback();
        } finally {
            foreach (array_reverse($tables) as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER ALL");
            }
        }
    }
}
