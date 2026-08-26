<?php

namespace Modules\Finance\CostControl\ValueObjects;

final readonly class DeferredCostDeliveryFailure
{
    /** @param array<string, int|string> $evidence */
    public function __construct(
        public string $code,
        public array $evidence = [],
    ) {}
}
