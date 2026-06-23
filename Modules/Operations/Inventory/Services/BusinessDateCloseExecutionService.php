<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;

class BusinessDateCloseExecutionService
{
    private BusinessDateCloseService $closeService;
    private InventoryPostingControlCoordinator $coordinator;

    public function __construct(
        BusinessDateCloseService $closeService,
        InventoryPostingControlCoordinator $coordinator
    ) {
        $this->closeService = $closeService;
        $this->coordinator = $coordinator;
    }

    public function executeClose(string $businessDateId): PropertyBusinessDate
    {
        return $this->coordinator->executeOnce(function () use ($businessDateId) {
            return DB::transaction(function () use ($businessDateId) {

                
                $businessDate = PropertyBusinessDate::where('id', $businessDateId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $mutatedBusinessDate = $this->closeService->close($businessDate);
                
                // Align the pure business state with the PostgreSQL persistence contract
                $mutatedBusinessDate->status = PropertyBusinessDateStatusEnum::Closed;
                $mutatedBusinessDate->is_open = null;
                
                $mutatedBusinessDate->save();

                return $mutatedBusinessDate;
            }, 1);
        });
    }
}
