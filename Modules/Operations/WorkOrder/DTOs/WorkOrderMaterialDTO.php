<?php

namespace Modules\Operations\WorkOrder\DTOs;

use Illuminate\Http\Request;

class WorkOrderMaterialDTO
{
    public function __construct(
        public readonly string $workOrderId,
        public readonly string $itemName,
        public readonly float $quantity,
        public readonly float $unitCost = 0.0,
        public readonly ?string $materialType = null,
        public readonly ?string $materialId = null,
    ) {}

    public static function fromRequest(string $workOrderId, Request $request): self
    {
        return new self(
            workOrderId: $workOrderId,
            itemName: $request->input('item_name'),
            quantity: (float) $request->input('quantity', 1),
            unitCost: (float) $request->input('unit_cost', 0),
            materialType: $request->input('material_type'),
            materialId: $request->input('material_id'),
        );
    }
}
