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
        public ?string $locationId,
        public ?string $valuationScope,
        public string $outcome,
        public ?string $deliveryMode,
        public ?string $ownershipId,
        public ?int $ownershipVersion,
        public ?string $cutoverId,
        public ?int $lastSynchronouslyOwnedSequence,
        public ?int $firstDeferredOwnedSequence,
    ) {
        if (trim($propertyId) === '' || trim($itemId) === '') {
            throw new InvalidArgumentException('Cost delivery posting decision requires Property and Item identity.');
        }

        if (! in_array($outcome, [self::NOT_ENROLLED, self::SYNCHRONOUS, self::DEFERRED], true)) {
            throw new InvalidArgumentException("Unsupported cost delivery outcome '{$outcome}'.");
        }

        if ($outcome === self::NOT_ENROLLED) {
            if ($locationId !== null
                || $valuationScope !== null
                || $deliveryMode !== null
                || $ownershipId !== null
                || $ownershipVersion !== null
                || $cutoverId !== null
                || $lastSynchronouslyOwnedSequence !== null
                || $firstDeferredOwnedSequence !== null) {
                throw new InvalidArgumentException('NOT_ENROLLED cannot carry delivery ownership or scope provenance.');
            }

            return;
        }

        if ($locationId === null
            || trim($locationId) === ''
            || $valuationScope === null
            || trim($valuationScope) === ''
            || $deliveryMode !== $outcome
            || $ownershipId === null
            || trim($ownershipId) === ''
            || $ownershipVersion === null
            || $ownershipVersion < 1) {
            throw new InvalidArgumentException(
                'Owned delivery decisions require canonical scope, exact mode, ownership ID, and positive version.'
            );
        }

        if ($outcome === self::SYNCHRONOUS
            && ($cutoverId !== null
                || $lastSynchronouslyOwnedSequence !== null
                || $firstDeferredOwnedSequence !== null)) {
            throw new InvalidArgumentException('SYNCHRONOUS delivery cannot carry cutover watermark provenance.');
        }

        if ($outcome === self::DEFERRED
            && ($cutoverId === null
                || trim($cutoverId) === ''
                || $lastSynchronouslyOwnedSequence === null
                || $lastSynchronouslyOwnedSequence < 0
                || $firstDeferredOwnedSequence === null
                || $firstDeferredOwnedSequence !== $lastSynchronouslyOwnedSequence + 1)) {
            throw new InvalidArgumentException('DEFERRED delivery requires exact cutover N/N+1 watermark provenance.');
        }
    }

    public static function notEnrolled(string $propertyId, string $itemId): self
    {
        return new self(
            $propertyId,
            $itemId,
            null,
            null,
            self::NOT_ENROLLED,
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }

    public static function synchronous(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $valuationScope,
        string $ownershipId,
        int $ownershipVersion
    ): self {
        return new self(
            $propertyId,
            $itemId,
            $locationId,
            $valuationScope,
            self::SYNCHRONOUS,
            self::SYNCHRONOUS,
            $ownershipId,
            $ownershipVersion,
            null,
            null,
            null,
        );
    }

    public static function deferred(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $valuationScope,
        string $ownershipId,
        int $ownershipVersion,
        string $cutoverId,
        int $lastSynchronouslyOwnedSequence,
        int $firstDeferredOwnedSequence
    ): self {
        return new self(
            $propertyId,
            $itemId,
            $locationId,
            $valuationScope,
            self::DEFERRED,
            self::DEFERRED,
            $ownershipId,
            $ownershipVersion,
            $cutoverId,
            $lastSynchronouslyOwnedSequence,
            $firstDeferredOwnedSequence,
        );
    }
}
