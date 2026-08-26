<?php

namespace Modules\Finance\CostControl\Repositories;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use RuntimeException;

class CostLedgerRepository
{
    public function append(CostLedgerEntryIntent $intent): CostLedgerEntry
    {
        $equivalence = $this->resolveIntent($intent, true);
        $this->assertAppendMayProceed($equivalence);

        try {
            // The savepoint keeps a PostgreSQL unique-race failure from aborting
            // a caller's outer apply transaction before canonical re-arbitration.
            return DB::transaction(fn (): CostLedgerEntry => $this->insert($intent));
        } catch (QueryException $exception) {
            $concurrent = $this->resolveIntent($intent, true);

            if ($concurrent->status !== CostLedgerSourceEquivalence::NO_EXISTING_EFFECT) {
                $this->throwControlledExistingEffect($concurrent, $exception);
            }

            if ($this->findByIdempotency(
                $intent->propertyId,
                $intent->idempotencyKey,
                $intent->entrySequence,
            ) !== null) {
                throw new RuntimeException(
                    'Duplicate idempotency detected. Controlled failure.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    public function resolveIntent(
        CostLedgerEntryIntent $intent,
        bool $forUpdate = false,
    ): CostLedgerSourceEquivalence {
        return $this->resolveRows(
            $intent->sourceInventoryTransactionId,
            fn (CostLedgerEntry $entry): bool => $this->matchesIntent($entry, $intent),
            $forUpdate,
        );
    }

    public function resolveInventoryTransaction(
        InventoryTransaction $source,
        bool $forUpdate = false,
    ): CostLedgerSourceEquivalence {
        return $this->resolveRows(
            $source->id,
            fn (CostLedgerEntry $entry): bool => $this->matchesInventorySource($entry, $source),
            $forUpdate,
        );
    }

    /** @return Collection<int, CostLedgerEntry> */
    public function findBySourceInventoryTransactionId(
        string $sourceId,
        bool $forUpdate = false,
    ): Collection {
        $query = CostLedgerEntry::where('source_inventory_transaction_id', $sourceId)
            ->orderBy('id');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function findByIdempotency(string $propertyId, string $idempotencyKey, int $sequence): ?CostLedgerEntry
    {
        return CostLedgerEntry::where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('entry_sequence', $sequence)
            ->first();
    }

    private function insert(CostLedgerEntryIntent $intent): CostLedgerEntry
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

    private function resolveRows(
        string $sourceId,
        callable $matches,
        bool $forUpdate,
    ): CostLedgerSourceEquivalence {
        $entries = $this->findBySourceInventoryTransactionId($sourceId, $forUpdate);

        if ($entries->isEmpty()) {
            return CostLedgerSourceEquivalence::none();
        }

        if ($entries->count() > 1) {
            return CostLedgerSourceEquivalence::legacyDuplicate($entries->count());
        }

        $entry = $entries->first();

        return $matches($entry)
            ? CostLedgerSourceEquivalence::exact($entry->id)
            : CostLedgerSourceEquivalence::conflict();
    }

    private function matchesIntent(CostLedgerEntry $entry, CostLedgerEntryIntent $intent): bool
    {
        return $entry->property_id === $intent->propertyId
            && $entry->source_inventory_transaction_id === $intent->sourceInventoryTransactionId
            && $entry->prior_cost_ledger_entry_id === $intent->priorCostLedgerEntryId
            && $entry->entry_type === $intent->entryType
            && $entry->idempotency_key === $intent->idempotencyKey
            && $entry->entry_sequence === $intent->entrySequence
            && $entry->currency_code === $intent->currencyCode
            && $this->decimalEquals($entry->quantity_delta, $intent->quantityDelta->getValue())
            && $this->decimalEquals($entry->unit_cost, $intent->unitCost->getValue())
            && $this->decimalEquals($entry->value_delta, $intent->valueDelta->getValue())
            && $entry->business_date?->format('Y-m-d') === $intent->businessDate
            && $this->timestampEquals($entry->occurred_at, $intent->occurredAt)
            && $entry->original_business_date?->format('Y-m-d') === $intent->originalBusinessDate
            && $this->canonicalMetadata($entry->metadata) === $this->canonicalMetadata($intent->metadata);
    }

    private function matchesInventorySource(CostLedgerEntry $entry, InventoryTransaction $source): bool
    {
        $transactionType = $this->transactionType($source);
        $entryType = $this->expectedEntryType($source);
        $idempotencyKey = $transactionType === TransactionTypeEnum::Reversal
            ? "reversal_ledger:{$source->id}"
            : trim((string) $source->idempotency_key);

        if ($entryType === null
            || $idempotencyKey === ''
            || $source->corrects_inventory_transaction_id !== null) {
            return false;
        }

        $matches = $entry->property_id === $source->property_id
            && $entry->source_inventory_transaction_id === $source->id
            && $entry->prior_cost_ledger_entry_id === null
            && $entry->entry_type === $entryType
            && $entry->idempotency_key === $idempotencyKey
            && $entry->entry_sequence === $source->valuation_sequence
            && $entry->currency_code === $source->currency_code
            && $this->decimalEquals($entry->quantity_delta, $source->quantity_change)
            && $this->decimalEquals($entry->unit_cost, $source->unit_cost)
            && $this->decimalEquals($entry->value_delta, $source->total_cost)
            && $entry->business_date?->format('Y-m-d') === $source->business_date?->format('Y-m-d')
            && $this->timestampEquals($entry->occurred_at, $source->occurred_at);

        if (! $matches) {
            return false;
        }

        if ($transactionType !== TransactionTypeEnum::Reversal) {
            return $source->reverses_inventory_transaction_id === null
                && $entry->original_business_date === null
                && $this->canonicalMetadata($entry->metadata) === [];
        }

        if ($source->reverses_inventory_transaction_id === null) {
            return false;
        }

        $original = InventoryTransaction::find($source->reverses_inventory_transaction_id);
        $metadata = $entry->metadata;

        if ($original === null || ! is_array($metadata)) {
            return false;
        }

        ksort($metadata);

        return $original->property_id === $source->property_id
            && $original->item_id === $source->item_id
            && $original->location_id === $source->location_id
            && $entry->original_business_date?->format('Y-m-d') === $original->business_date?->format('Y-m-d')
            && array_keys($metadata) === ['approval_reference', 'original_transaction_id', 'reversal_reason']
            && $metadata['original_transaction_id'] === $original->id
            && $metadata['approval_reference'] === $source->valuation_approval_reference
            && is_string($metadata['reversal_reason'])
            && trim($metadata['reversal_reason']) !== '';
    }

    private function expectedEntryType(InventoryTransaction $source): ?string
    {
        return match ($this->transactionType($source)) {
            TransactionTypeEnum::PurchaseReceipt => 'receipt',
            TransactionTypeEnum::Issue => 'issue',
            TransactionTypeEnum::TransferOut, TransactionTypeEnum::TransferIn => 'transfer',
            TransactionTypeEnum::AdjustmentIn, TransactionTypeEnum::AdjustmentOut => 'adjustment',
            TransactionTypeEnum::Reversal => 'reversal',
            default => null,
        };
    }

    private function transactionType(InventoryTransaction $source): ?TransactionTypeEnum
    {
        return $source->transaction_type instanceof TransactionTypeEnum
            ? $source->transaction_type
            : TransactionTypeEnum::tryFrom((string) $source->transaction_type);
    }

    private function decimalEquals(mixed $left, mixed $right): bool
    {
        return bccomp((string) $left, (string) $right, 4) === 0;
    }

    private function timestampEquals(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null || strtotime((string) $right) === false) {
            return false;
        }

        return $left->getTimestamp() === strtotime((string) $right);
    }

    /** @return array<mixed> */
    private function canonicalMetadata(?array $metadata): array
    {
        if ($metadata === null || $metadata === []) {
            return [];
        }

        foreach ($metadata as &$value) {
            if (is_array($value)) {
                $value = $this->canonicalMetadata($value);
            }
        }
        unset($value);

        if (! array_is_list($metadata)) {
            ksort($metadata);
        }

        return $metadata;
    }

    private function assertAppendMayProceed(CostLedgerSourceEquivalence $equivalence): void
    {
        if ($equivalence->status !== CostLedgerSourceEquivalence::NO_EXISTING_EFFECT) {
            $this->throwControlledExistingEffect($equivalence);
        }
    }

    private function throwControlledExistingEffect(
        CostLedgerSourceEquivalence $equivalence,
        ?QueryException $previous = null,
    ): never {
        $message = match ($equivalence->status) {
            CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT => 'Duplicate idempotency detected. Controlled failure.',
            CostLedgerSourceEquivalence::CONFLICTING_EFFECT => 'CC_P01C_COST_LEDGER_SOURCE_CONFLICT',
            CostLedgerSourceEquivalence::LEGACY_SOURCE_DUPLICATE_CONTRADICTION => 'CC_P01C_COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION',
            default => 'CC_P01C_COST_LEDGER_SOURCE_ARBITRATION_FAILED',
        };

        throw new RuntimeException($message, 0, $previous);
    }
}
