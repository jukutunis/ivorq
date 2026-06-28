<?php

namespace Modules\Finance\CostControl\ValueObjects;

use DateTime;
use InvalidArgumentException;

/**
 * Immutable value object carrying the fields required to produce one
 * CostLedgerEntryIntent via ControlledValuationCostLedgerAdapter.
 *
 * Contains only the fields that the existing legacy Cost Ledger append
 * contract requires. Does not introduce before/after AVCO state,
 * enrollment activation, GL, or Outbox semantics.
 */
final class ControlledValuationCostLedgerIntent
{
    public readonly string $propertyId;
    public readonly string $sourceInventoryTransactionId;
    public readonly ?string $priorCostLedgerEntryId;
    public readonly string $entryType;
    public readonly string $idempotencyKey;
    public readonly int $entrySequence;
    public readonly string $currencyCode;
    public readonly AvcoDecimal $quantityDelta;
    public readonly AvcoDecimal $unitCost;
    public readonly AvcoDecimal $valueDelta;
    public readonly string $businessDate;
    public readonly string $occurredAt;
    public readonly ?string $originalBusinessDate;
    public readonly ?array $metadata;

    private const ALLOWED_ENTRY_TYPES = [
        'receipt', 'issue', 'adjustment', 'transfer', 'correction', 'reversal',
    ];

    public function __construct(
        string $propertyId,
        string $sourceInventoryTransactionId,
        ?string $priorCostLedgerEntryId,
        string $entryType,
        string $idempotencyKey,
        int $entrySequence,
        string $currencyCode,
        AvcoDecimal $quantityDelta,
        AvcoDecimal $unitCost,
        AvcoDecimal $valueDelta,
        string $businessDate,
        string $occurredAt,
        ?string $originalBusinessDate = null,
        ?array $metadata = null
    ) {
        if (trim($propertyId) === '') {
            throw new InvalidArgumentException('propertyId cannot be blank.');
        }
        if (trim($sourceInventoryTransactionId) === '') {
            throw new InvalidArgumentException('sourceInventoryTransactionId cannot be blank.');
        }
        if (!in_array($entryType, self::ALLOWED_ENTRY_TYPES, true)) {
            throw new InvalidArgumentException(
                "entryType '{$entryType}' is not valid. " .
                "Allowed: " . implode(', ', self::ALLOWED_ENTRY_TYPES) . '.'
            );
        }
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('idempotencyKey cannot be blank.');
        }
        if ($entrySequence <= 0) {
            throw new InvalidArgumentException('entrySequence must be positive.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            throw new InvalidArgumentException(
                "currencyCode must be exactly three uppercase letters. Got: '{$currencyCode}'."
            );
        }

        $d = DateTime::createFromFormat('Y-m-d', $businessDate);
        if (!$d || $d->format('Y-m-d') !== $businessDate) {
            throw new InvalidArgumentException(
                "businessDate must be a valid calendar date (Y-m-d). Got: '{$businessDate}'."
            );
        }

        if ($originalBusinessDate !== null) {
            $od = DateTime::createFromFormat('Y-m-d', $originalBusinessDate);
            if (!$od || $od->format('Y-m-d') !== $originalBusinessDate) {
                throw new InvalidArgumentException(
                    "originalBusinessDate must be a valid calendar date (Y-m-d). Got: '{$originalBusinessDate}'."
                );
            }
        }

        if (trim($occurredAt) === '' || !strtotime($occurredAt)) {
            throw new InvalidArgumentException(
                "occurredAt must be a valid parseable date-time. Got: '{$occurredAt}'."
            );
        }

        $this->propertyId                   = $propertyId;
        $this->sourceInventoryTransactionId = $sourceInventoryTransactionId;
        $this->priorCostLedgerEntryId       = $priorCostLedgerEntryId;
        $this->entryType                    = $entryType;
        $this->idempotencyKey               = $idempotencyKey;
        $this->entrySequence                = $entrySequence;
        $this->currencyCode                 = $currencyCode;
        $this->quantityDelta                = $quantityDelta;
        $this->unitCost                     = $unitCost;
        $this->valueDelta                   = $valueDelta;
        $this->businessDate                 = $businessDate;
        $this->occurredAt                   = $occurredAt;
        $this->originalBusinessDate         = $originalBusinessDate;
        $this->metadata                     = $metadata;
    }
}
