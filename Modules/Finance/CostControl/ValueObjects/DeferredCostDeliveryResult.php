<?php

namespace Modules\Finance\CostControl\ValueObjects;

final readonly class DeferredCostDeliveryResult
{
    public const DELIVERED = 'DELIVERED';

    public const ALREADY_DELIVERED = 'ALREADY_DELIVERED';

    public const RECOVERY_REQUIRED = 'RECOVERY_REQUIRED';

    public const FAILED = 'FAILED';

    public const REJECTED = 'REJECTED';

    /**
     * @param  list<string>  $outboxMessageIds
     * @param  list<string>  $sourceInventoryTransactionIds
     */
    public function __construct(
        public string $status,
        public string $code,
        public array $outboxMessageIds,
        public array $sourceInventoryTransactionIds,
    ) {}

    /** @param list<array{outbox_id:string,source_id:string}> $legs */
    public static function delivered(array $legs): self
    {
        return self::fromLegs(self::DELIVERED, 'DEFERRED_COST_DELIVERY_APPLIED', $legs);
    }

    /** @param list<array{outbox_id:string,source_id:string}> $legs */
    public static function alreadyDelivered(array $legs): self
    {
        return self::fromLegs(self::ALREADY_DELIVERED, 'DEFERRED_COST_DELIVERY_ALREADY_DELIVERED', $legs);
    }

    /** @param list<array{outbox_id:string,source_id:string}> $legs */
    public static function recoveryRequired(string $code, array $legs): self
    {
        return self::fromLegs(self::RECOVERY_REQUIRED, $code, $legs);
    }

    /** @param list<array{outbox_id:string,source_id:string}> $legs */
    public static function failed(string $code, array $legs): self
    {
        return self::fromLegs(self::FAILED, $code, $legs);
    }

    /** @param list<array{outbox_id:string,source_id:string}> $legs */
    public static function rejected(string $code, array $legs = []): self
    {
        return self::fromLegs(self::REJECTED, $code, $legs);
    }

    /** @param list<array{outbox_id:string,source_id:string}> $legs */
    private static function fromLegs(string $status, string $code, array $legs): self
    {
        return new self(
            $status,
            $code,
            array_values(array_map(fn (array $leg): string => $leg['outbox_id'], $legs)),
            array_values(array_map(fn (array $leg): string => $leg['source_id'], $legs)),
        );
    }
}
