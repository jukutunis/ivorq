<?php

namespace Modules\Operations\WorkOrder\Services;

use Modules\Operations\WorkOrder\Models\WorkOrderLabor;
use Modules\Operations\WorkOrder\DTOs\WorkOrderLaborDTO;
use Modules\Operations\WorkOrder\Enums\WorkOrderLaborStatusEnum;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;

class WorkOrderLaborService
{
    public function logTime(WorkOrderLaborDTO $dto, string $userId): WorkOrderLabor
    {
        return WorkOrderLabor::create([
            'work_order_id' => $dto->workOrderId,
            'user_id' => $dto->userId,
            'status' => $dto->status,
            'started_at' => $dto->status === WorkOrderLaborStatusEnum::Started ? now() : null,
            'ended_at' => $dto->status === WorkOrderLaborStatusEnum::Completed ? now() : null,
            'notes' => $dto->notes,
            'created_by' => $userId,
        ]);
    }
}
