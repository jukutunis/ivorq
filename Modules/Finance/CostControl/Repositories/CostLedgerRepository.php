<?php
namespace Modules\Finance\CostControl\Repositories;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
class CostLedgerRepository
{
    public function append(CostLedgerEntryIntent $intent): CostLedgerEntry
    {
        return CostLedgerEntry::create([
            'property_id' => $intent->propertyId,
            'source_inventory_transaction_id' => $intent->sourceInventoryTransactionId,
            'prior_cost_ledger_entry_id' => $intent->priorCostLedgerEntryId,
            'entry_type' => $intent->entryType,
            'idempotency_key' => $intent->idempotencyKey,
            'entry_sequence' => $intent->entrySequence,
            'currency_code' => $intent->currencyCode,
            'quantity_delta' => $intent->quantityDelta->getValue(),
            'unit_cost' => $intent->unitCost->getValue(),
            'value_delta' => $intent->valueDelta->getValue(),
            'business_date' => $intent->businessDate,
            'occurred_at' => $intent->occurredAt,
            'original_business_date' => $intent->originalBusinessDate,
            'metadata' => $intent->metadata,
        ]);
    }
    public function findByIdempotency(string $propertyId, string $idempotencyKey, int $sequence): ?CostLedgerEntry
    {
        return CostLedgerEntry::where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('entry_sequence', $sequence)
            ->first();
    }
}
