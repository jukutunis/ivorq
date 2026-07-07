<?php

namespace Modules\Operations\Inventory\Services;

use Carbon\Carbon;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Enums\InventoryCostEligibilityStatusEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Models\GoodsReceipt;
use Modules\Operations\Inventory\Models\GoodsReceiptLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryStockMovement;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;

class InventoryAvcoCostProjectionService
{
    public function project(string $propertyId, string $inventoryItemId): array
    {
        $property = Property::findOrFail($propertyId);
        $baseCurrency = strtoupper((string) ($property->currency ?? 'IDR'));

        InventoryItem::where('property_id', $propertyId)
            ->where('id', $inventoryItemId)
            ->firstOrFail();

        $movements = InventoryStockMovement::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('inventory_item_id', $inventoryItemId)
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $costedQuantity = AvcoDecimal::zero();
        $derivedControlledValue = AvcoDecimal::zero();
        $derivedAvco = null;

        $controlledLedgerQuantity = AvcoDecimal::zero();

        $eligibilityStatus = InventoryCostEligibilityStatusEnum::CostingReady;
        $blockingReason = null;
        $blockingMovementId = null;
        $lastCostEligibleMovementId = null;
        $lastCostEligibleAt = null;

        $consumptionCostEvidence = [];

        $transferPairs = [];

        foreach ($movements as $movement) {
            $type = $movement->movement_type instanceof InventoryMovementTypeEnum
                ? $movement->movement_type
                : InventoryMovementTypeEnum::tryFrom((string) $movement->movement_type);

            $direction = $movement->direction instanceof InventoryMovementDirectionEnum
                ? $movement->direction
                : InventoryMovementDirectionEnum::tryFrom((string) $movement->direction);

            if (!$type || !$direction) {
                if ($eligibilityStatus === InventoryCostEligibilityStatusEnum::CostingReady) {
                    $eligibilityStatus = InventoryCostEligibilityStatusEnum::CostingBlockedInconsistentMovementEvidence;
                    $blockingReason = 'Unrecognized movement type or direction.';
                    $blockingMovementId = $movement->id;
                }
                continue;
            }

            $quantityDecimal = new AvcoDecimal((string) $movement->quantity);

            if ($direction === InventoryMovementDirectionEnum::In) {
                $controlledLedgerQuantity = $controlledLedgerQuantity->add($quantityDecimal);
            } else {
                $controlledLedgerQuantity = $controlledLedgerQuantity->sub($quantityDecimal);
            }

            if ($eligibilityStatus !== InventoryCostEligibilityStatusEnum::CostingReady) {
                continue;
            }

            switch ($type) {
                case InventoryMovementTypeEnum::GoodsReceipt:
                    $result = $this->processGoodsReceipt(
                        $movement, $quantityDecimal, $baseCurrency,
                        $costedQuantity, $derivedControlledValue, $derivedAvco
                    );

                    if ($result['status'] !== InventoryCostEligibilityStatusEnum::CostingReady) {
                        $eligibilityStatus = $result['status'];
                        $blockingReason = $result['reason'];
                        $blockingMovementId = $movement->id;
                        break;
                    }

                    $costedQuantity = $result['costed_quantity'];
                    $derivedControlledValue = $result['derived_controlled_value'];
                    $derivedAvco = $result['derived_avco'];
                    $lastCostEligibleMovementId = $movement->id;
                    $lastCostEligibleAt = $movement->occurred_at;
                    break;

                case InventoryMovementTypeEnum::IssueConsumption:
                    if ($derivedAvco === null || $costedQuantity->isZero()) {
                        $eligibilityStatus = InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence;
                        $blockingReason = 'Cannot issue with no prior cost evidence.';
                        $blockingMovementId = $movement->id;
                        break;
                    }

                    if ($costedQuantity->compareTo($quantityDecimal) < 0) {
                        $eligibilityStatus = InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence;
                        $blockingReason = 'Costed quantity insufficient for issue quantity.';
                        $blockingMovementId = $movement->id;
                        break;
                    }

                    $issueCostEvidence = $derivedAvco->mul($quantityDecimal);
                    $costedQuantity = $costedQuantity->sub($quantityDecimal);
                    $derivedControlledValue = $derivedControlledValue->sub($issueCostEvidence);

                    if (!$costedQuantity->isZero()) {
                        $derivedAvco = $derivedControlledValue->div($costedQuantity);
                    } else {
                        $derivedAvco = null;
                    }

                    $consumptionCostEvidence[] = [
                        'movement_id' => $movement->id,
                        'movement_type' => $type->value,
                        'issue_quantity' => $movement->quantity,
                        'avco_at_issue' => $issueCostEvidence->getValue(),
                        'occurred_at' => $movement->occurred_at,
                    ];

                    $lastCostEligibleMovementId = $movement->id;
                    $lastCostEligibleAt = $movement->occurred_at;
                    break;

                case InventoryMovementTypeEnum::TransferOut:
                    $correlationId = $movement->correlation_id;
                    $transferPairs[$correlationId] = [
                        'out' => $movement,
                        'in' => $transferPairs[$correlationId]['in'] ?? null,
                    ];
                    break;

                case InventoryMovementTypeEnum::TransferIn:
                    $correlationId = $movement->correlation_id;
                    $transferPairs[$correlationId] = [
                        'out' => $transferPairs[$correlationId]['out'] ?? null,
                        'in' => $movement,
                    ];

                    if (isset($transferPairs[$correlationId]['out'])
                        && isset($transferPairs[$correlationId]['in'])) {
                        $pair = $transferPairs[$correlationId];
                        $pairResult = $this->validateTransferPair(
                            $pair['out'], $pair['in'], $propertyId
                        );

                        if (!$pairResult['valid']) {
                            $eligibilityStatus = InventoryCostEligibilityStatusEnum::CostingBlockedInconsistentMovementEvidence;
                            $blockingReason = $pairResult['reason'];
                            $blockingMovementId = $pairResult['movement_id'];
                        }
                    }
                    break;

                case InventoryMovementTypeEnum::CountVarianceIn:
                case InventoryMovementTypeEnum::CountVarianceOut:
                case InventoryMovementTypeEnum::ManualAdjustmentIn:
                case InventoryMovementTypeEnum::ManualAdjustmentOut:
                    $eligibilityStatus = InventoryCostEligibilityStatusEnum::CostingBlockedUnvaluedMovement;
                    $blockingReason = sprintf(
                        'Movement type %s blocks cost continuity without an approved valuation source.',
                        $type->value
                    );
                    $blockingMovementId = $movement->id;
                    break;
            }
        }

        foreach ($transferPairs as $correlationId => $pair) {
            if (isset($pair['out']) && !isset($pair['in'])) {
                if ($eligibilityStatus === InventoryCostEligibilityStatusEnum::CostingReady) {
                    $eligibilityStatus = InventoryCostEligibilityStatusEnum::CostingBlockedInconsistentMovementEvidence;
                    $blockingReason = sprintf(
                        'Transfer has unpaired OUTBOUND leg with correlation_id %s.',
                        $correlationId
                    );
                    $blockingMovementId = $pair['out']->id;
                }
            } elseif (!isset($pair['out']) && isset($pair['in'])) {
                if ($eligibilityStatus === InventoryCostEligibilityStatusEnum::CostingReady) {
                    $eligibilityStatus = InventoryCostEligibilityStatusEnum::CostingBlockedInconsistentMovementEvidence;
                    $blockingReason = sprintf(
                        'Transfer has unpaired INBOUND leg with correlation_id %s.',
                        $correlationId
                    );
                    $blockingMovementId = $pair['in']->id;
                }
            }
        }

        return [
            'property_id' => $propertyId,
            'inventory_item_id' => $inventoryItemId,
            'controlled_ledger_quantity' => $controlledLedgerQuantity->getValue(),
            'costed_controlled_quantity' => $costedQuantity->getValue(),
            'derived_avco_unit_cost' => $derivedAvco?->getValue(),
            'derived_controlled_cost_value' => $eligibilityStatus === InventoryCostEligibilityStatusEnum::CostingReady
                || ($derivedControlledValue !== null && !$derivedControlledValue->isZero())
                ? $derivedControlledValue->getValue() : null,
            'base_currency_code' => $baseCurrency,
            'eligibility_status' => $eligibilityStatus->value,
            'blocking_reason' => $blockingReason,
            'blocking_movement_id' => $blockingMovementId,
            'last_cost_eligible_movement_id' => $lastCostEligibleMovementId,
            'last_cost_eligible_at' => $lastCostEligibleAt,
            'consumption_cost_evidence' => $consumptionCostEvidence,
            'projection_as_of' => Carbon::now()->toIso8601String(),
        ];
    }

    private function processGoodsReceipt(
        InventoryStockMovement $movement,
        AvcoDecimal $quantityDecimal,
        string $baseCurrency,
        AvcoDecimal $costedQuantity,
        AvcoDecimal $derivedControlledValue,
        ?AvcoDecimal $derivedAvco
    ): array {
        $goodsReceiptLine = GoodsReceiptLine::with('goodsReceipt')
            ->find($movement->source_id);

        if (!$goodsReceiptLine) {
            return [
                'status' => InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence,
                'reason' => 'Goods Receipt Line source not found.',
            ];
        }

        $receipt = $goodsReceiptLine->goodsReceipt;

        if (!$receipt || (string) $receipt->status !== 'Posted') {
            return [
                'status' => InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence,
                'reason' => 'Goods Receipt is not posted.',
            ];
        }

        $poLine = PurchaseOrderLine::with('purchaseOrder')
            ->find($goodsReceiptLine->purchase_order_line_id);

        if (!$poLine) {
            return [
                'status' => InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence,
                'reason' => 'Purchase Order Line not found.',
            ];
        }

        $unitCostRaw = (string) $poLine->unit_cost;
        $unitCostNumeric = (float) $unitCostRaw;

        if ($unitCostNumeric <= 0) {
            return [
                'status' => InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence,
                'reason' => 'Purchase Order Line unit cost is zero, negative, or invalid.',
            ];
        }

        $po = $poLine->purchaseOrder;

        if (!$po) {
            return [
                'status' => InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence,
                'reason' => 'Purchase Order not found.',
            ];
        }

        $poStatus = (string) $po->status;
        if (!in_array($poStatus, ['Approved', 'Issued', 'PartiallyReceived', 'FullyReceived'], true)) {
            return [
                'status' => InventoryCostEligibilityStatusEnum::CostingBlockedInsufficientCostEvidence,
                'reason' => 'Purchase Order is not in an approved commercial state.',
            ];
        }

        $poCurrency = strtoupper((string) ($po->currency_code ?? 'IDR'));
        $exchangeRateRaw = (string) ($po->exchange_rate ?? '0');
        $exchangeRateNumeric = (float) $exchangeRateRaw;

        $receiptValue = null;

        if ($poCurrency === $baseCurrency) {
            $unitCostDecimal = new AvcoDecimal($unitCostRaw);
            $receiptValue = $unitCostDecimal->mul($quantityDecimal);
        } elseif ($exchangeRateNumeric > 0) {
            $exchangeRateDecimal = new AvcoDecimal($exchangeRateRaw);
            $unitCostDecimal = new AvcoDecimal($unitCostRaw);
            $convertedUnitCost = $unitCostDecimal->mul($exchangeRateDecimal);
            $receiptValue = $convertedUnitCost->mul($quantityDecimal);
        } else {
            return [
                'status' => InventoryCostEligibilityStatusEnum::CostingBlockedFxUnsupported,
                'reason' => sprintf(
                    'Goods Receipt source currency %s differs from property base currency %s '
                    . 'and no valid source-proven exchange rate exists.',
                    $poCurrency,
                    $baseCurrency
                ),
            ];
        }

        $newCostedQuantity = $costedQuantity->add($quantityDecimal);
        $newDerivedControlledValue = $derivedControlledValue->add($receiptValue);
        $newDerivedAvco = $newDerivedControlledValue->div($newCostedQuantity);

        return [
            'status' => InventoryCostEligibilityStatusEnum::CostingReady,
            'reason' => null,
            'costed_quantity' => $newCostedQuantity,
            'derived_controlled_value' => $newDerivedControlledValue,
            'derived_avco' => $newDerivedAvco,
        ];
    }

    private function validateTransferPair(
        InventoryStockMovement $out,
        InventoryStockMovement $in,
        string $propertyId
    ): array {
        if ($out->inventory_item_id !== $in->inventory_item_id) {
            return [
                'valid' => false,
                'reason' => sprintf(
                    'Transfer pair has mismatched items: %s vs %s.',
                    $out->inventory_item_id,
                    $in->inventory_item_id
                ),
                'movement_id' => $out->id,
            ];
        }

        if ($out->property_id !== $in->property_id) {
            return [
                'valid' => false,
                'reason' => sprintf(
                    'Transfer pair crosses property boundaries: %s vs %s.',
                    $out->property_id,
                    $in->property_id
                ),
                'movement_id' => $out->id,
            ];
        }

        if (bccomp((string) $out->quantity, (string) $in->quantity, 3) !== 0) {
            return [
                'valid' => false,
                'reason' => sprintf(
                    'Transfer pair has mismatched quantities: %s vs %s.',
                    (string) $out->quantity,
                    (string) $in->quantity
                ),
                'movement_id' => $out->id,
            ];
        }

        return ['valid' => true, 'reason' => null, 'movement_id' => null];
    }
}
