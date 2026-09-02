<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryEligibleContext;
use RuntimeException;

final class DeferredSingleTransactionValuationHandler
{
    public function __construct(
        private readonly ControlledValuationApplyCoordinator $coordinator,
    ) {}

    public function apply(DeferredCostDeliveryEligibleContext $context): string
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }
        if ($context->requiresPairedApplication) {
            throw new RuntimeException('CC_P01E_SINGLE_HANDLER_RECEIVED_TRANSFER_PAIR');
        }

        return $this->coordinator->applyDeferred($context);
    }
}
