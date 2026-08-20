<?php

namespace Modules\Operations\Inventory\ValueObjects;

use InvalidArgumentException;

final readonly class CostDeliveryPostingDecision
{
    public const NOT_ENROLLED = 'NOT_ENROLLED';

    public const SYNCHRONOUS = 'SYNCHRONOUS';

    public const DEFERRED = 'DEFERRED';

    private function __construct(
        public string $propertyId,
        public string $itemId,
        public string $outcome,
        public ?string $deliveryMode,
        public ?string $ownershipId,
        public ?int $ownershipVersion,
        public ?string $cutoverId,
    ) {
        if (trim($propertyId) === '' || trim($itemId) === '') {
            throw new InvalidArgumentException('Cost delivery posting decision requires Property and Item identity.');
        }

        if (! in_array($outcome, [self::NOT_ENROLLED, self::SYNCHRONOUS, self::DEFERRED], true)) {
            throw new InvalidArgumentException("Unsupported cost delivery outcome '{$outcome}'.");
        }

        if ($outcome === self::NOT_ENROLLED) {
            if ($deliveryMode !== null || $ownershipId !== null || $ownershipVersion !== null || $cutoverId !== null) {
                throw new InvalidArgumentException('NOT_ENROLLED cannot carry delivery ownership provenance.');
            }

            return;
        }

        if ($deliveryMode !== $outcome || $ownershipId === null || $ownershipVersion === null || $ownershipVersion < 1) {
            throw new InvalidArgumentException('Owned delivery decisions require exact mode, ownership ID, and positive version.');
        }

        if ($outcome === self::SYNCHRONOUS && $cutoverId !== null) {
            throw new InvalidArgumentException('SYNCHRONOUS delivery cannot carry cutover provenance.');
        }

        if ($outcome === self::DEFERRED && $cutoverId === null) {
            throw new InvalidArgumentException('DEFERRED delivery requires cutover provenance.');
        }
    }

    public static function notEnrolled(string $propertyId, string $itemId): self
    {
        return new self($propertyId, $itemId, self::NOT_ENROLLED, null, null, null, null);
    }

    public static function synchronous(
        string $propertyId,
        string $itemId,
        string $ownershipId,
        int $ownershipVersion
    ): self {
        return new self(
            $propertyId,
            $itemId,
            self::SYNCHRONOUS,
            self::SYNCHRONOUS,
            $ownershipId,
            $ownershipVersion,
            null,
        );
    }

    public static function deferred(
        string $propertyId,
        string $itemId,
        string $ownershipId,
        int $ownershipVersion,
        string $cutoverId
    ): self {
        return new self(
            $propertyId,
            $itemId,
            self::DEFERRED,
            self::DEFERRED,
            $ownershipId,
            $ownershipVersion,
            $cutoverId,
        );
    }
}
