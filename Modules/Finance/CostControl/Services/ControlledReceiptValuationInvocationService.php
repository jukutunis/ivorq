<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Finance\CostControl\Services\ControlledValuationApplyCoordinator;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use RuntimeException;

/**
 * final invocation service to coordinate posting receipt stock transactions
 * and applying controlled receipt valuations in a single atomic database transaction.
 */
final class ControlledReceiptValuationInvocationService
{
    public function __construct(
        private readonly InventoryPostingControlCoordinator $postingCoordinator,
        private readonly ControlledValuationApplyCoordinator $applyCoordinator,
        private readonly CostAuthorityEnrollmentRepository $enrollmentRepository
    ) {}

    /**
     * Atomically invoke controlled receipt valuation.
     *
     * @param string $propertyId
     * @param string $locationId
     * @param string $itemId
     * @param InventoryLedgerPostingIntent $intent
     * @param string|null $actorId
     * @return void
     * @throws RuntimeException
     */
    public function invokeReceipt(
        string $propertyId,
        string $locationId,
        string $itemId,
        InventoryLedgerPostingIntent $intent,
        ?string $actorId = null
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('ControlledReceiptValuationInvocationService::invokeReceipt requires an active transaction.');
        }

        // 1. Post inventory transaction first (creates the canonical InventoryTransaction evidence)
        $tx = $this->postingCoordinator->post($intent, $actorId);

        // 2. Build ControlledValuationCostLedgerIntent from transaction evidence
        $unitCost = new AvcoDecimal((string) $tx->unit_cost);
        $valueDelta = new AvcoDecimal((string) $tx->total_cost);
        $quantityDelta = new AvcoDecimal((string) $tx->quantity_change);

        $ledgerIntent = new ControlledValuationCostLedgerIntent(
            propertyId: $tx->property_id,
            sourceInventoryTransactionId: $tx->id,
            priorCostLedgerEntryId: null,
            entryType: 'receipt', // Enforced receipt type
            idempotencyKey: $tx->idempotency_key,
            entrySequence: (int) $tx->valuation_sequence,
            currencyCode: $tx->currency_code,
            quantityDelta: $quantityDelta,
            unitCost: $unitCost,
            valueDelta: $valueDelta,
            businessDate: $tx->business_date->format('Y-m-d'),
            occurredAt: $tx->occurred_at->format('Y-m-d H:i:s')
        );

        // 3. Call ControlledValuationApplyCoordinator to apply it
        $this->applyCoordinator->apply($propertyId, $locationId, $itemId, $ledgerIntent);
    }
}
