<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'adjustment_number' => $this->adjustment_number,
            'location_id'       => $this->location_id,

            'adjustment_type' => [
                'value' => $this->adjustment_type->value,
                'label' => $this->adjustment_type->label(),
            ],

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'reason'           => $this->reason,
            'rejection_reason' => $this->rejection_reason,

            'submitted_by' => $this->submitted_by,
            'submitted_at' => $this->submitted_at?->toIso8601String(),

            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),

            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // created_by, updated_by, deleted_at intentionally omitted

            'location' => $this->whenLoaded('location', fn() => $this->location
                ? ['id' => $this->location->id, 'location_code' => $this->location->location_code, 'name' => $this->location->name]
                : null
            ),

            'submitted_by_user' => $this->whenLoaded('submittedBy', fn() => $this->submittedBy
                ? ['id' => $this->submittedBy->id, 'name' => $this->submittedBy->name]
                : null
            ),

            'approved_by_user' => $this->whenLoaded('approvedBy', fn() => $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null
            ),

            'rejected_by_user' => $this->whenLoaded('rejectedBy', fn() => $this->rejectedBy
                ? ['id' => $this->rejectedBy->id, 'name' => $this->rejectedBy->name]
                : null
            ),

            'lines_count' => $this->whenCounted('lines'),
            'lines'       => InventoryAdjustmentLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
