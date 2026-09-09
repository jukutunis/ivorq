<?php

namespace Modules\Operations\Inventory\ValueObjects;

use InvalidArgumentException;

final class InventoryAdjustmentIdempotencyKey
{
    public const MAX_LENGTH = 64;

    public static function approval(string $adjustmentId, string $lineId): string
    {
        $key = "adj_{$adjustmentId}_{$lineId}_ap";

        if (strlen($key) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Adjustment approval idempotency key exceeds 64 characters.');
        }

        return $key;
    }
}
