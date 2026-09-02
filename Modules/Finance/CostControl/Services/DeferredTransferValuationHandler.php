<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryEligibleContext;
use RuntimeException;

final class DeferredTransferValuationHandler
{
    public function __construct(
        private readonly ControlledTransferValuationApplyCoordinator $coordinator,
    ) {}

    /** @return array{outbound:string,inbound:string} */
    public function apply(DeferredCostDeliveryEligibleContext $context): array
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }
        if (! $context->requiresPairedApplication || $context->pairedLeg === null) {
            throw new RuntimeException('CC_P01E_TRANSFER_HANDLER_REQUIRES_COMPLETE_PAIR');
        }

        return $this->coordinator->applyDeferred($context);
    }
}
