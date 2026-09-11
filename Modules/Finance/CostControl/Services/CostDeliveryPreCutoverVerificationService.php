<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Repositories\CostDeliveryCutoverPreflightRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryCutoverRequest;
use RuntimeException;

final class CostDeliveryPreCutoverVerificationService
{
    public function __construct(
        private readonly CostDeliveryModeOwnershipRepository $ownershipRepository,
        private readonly CostDeliveryCutoverPreflightRepository $preflightRepository,
        private readonly CostDeliveryCutoverPreflightService $preflightService,
    ) {}

    /** @return array{period:object,scopes:array<int,array<string,mixed>>} */
    public function verify(CostDeliveryCutoverRequest $request): array
    {
        return DB::transaction(function () use ($request): array {
            $pilots = $this->preflightRepository->lockPilotRows();
            $ownership = $this->ownershipRepository->findForUpdateByPropertyItem(
                $request->propertyId,
                $request->itemId,
            );
            if ($ownership === null) {
                throw new RuntimeException('CUTOVER_BLOCKED_OWNERSHIP_MISSING');
            }

            return $this->preflightService->prove($request, $ownership, $pilots);
        }, 1);
    }
}
