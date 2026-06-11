<?php

namespace Modules\Operations\WorkOrder\DTOs;

use Modules\Operations\WorkOrder\Enums\WorkOrderLaborStatusEnum;
use Illuminate\Http\Request;

class WorkOrderLaborDTO
{
    public function __construct(
        public readonly string $workOrderId,
        public readonly string $userId,
        public readonly WorkOrderLaborStatusEnum $status,
        public readonly ?string $notes = null,
    ) {}

    public static function fromRequest(string $workOrderId, Request $request): self
    {
        return new self(
            workOrderId: $workOrderId,
            userId: $request->user()->id,
            status: WorkOrderLaborStatusEnum::from($request->input('status')),
            notes: $request->input('notes'),
        );
    }
}
