<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;

final readonly class CostLedgerSourceEquivalence
{
    public const NO_EXISTING_EFFECT = 'NO_EXISTING_EFFECT';

    public const EXACT_EQUIVALENT_EFFECT = 'EXACT_EQUIVALENT_EFFECT';

    public const CONFLICTING_EFFECT = 'CONFLICTING_EFFECT';

    public const LEGACY_SOURCE_DUPLICATE_CONTRADICTION = 'LEGACY_SOURCE_DUPLICATE_CONTRADICTION';

    public function __construct(
        public string $status,
        public ?string $costLedgerEntryId,
        public int $sourceRowCount,
    ) {
        if (! in_array($status, [
            self::NO_EXISTING_EFFECT,
            self::EXACT_EQUIVALENT_EFFECT,
            self::CONFLICTING_EFFECT,
            self::LEGACY_SOURCE_DUPLICATE_CONTRADICTION,
        ], true)) {
            throw new InvalidArgumentException('Unknown Cost Ledger source-equivalence status.');
        }

        if ($sourceRowCount < 0) {
            throw new InvalidArgumentException('Cost Ledger source row count cannot be negative.');
        }

        if (($status === self::EXACT_EQUIVALENT_EFFECT) !== ($costLedgerEntryId !== null)) {
            throw new InvalidArgumentException('Exact Cost Ledger equivalence requires one entry identity.');
        }
    }

    public static function none(): self
    {
        return new self(self::NO_EXISTING_EFFECT, null, 0);
    }

    public static function exact(string $entryId): self
    {
        return new self(self::EXACT_EQUIVALENT_EFFECT, $entryId, 1);
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICTING_EFFECT, null, 1);
    }

    public static function legacyDuplicate(int $count): self
    {
        return new self(self::LEGACY_SOURCE_DUPLICATE_CONTRADICTION, null, $count);
    }
}
