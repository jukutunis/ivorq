<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'transfer_number'  => $this->transfer_number,
            'from_location_id' => $this->from_location_id,
            'to_location_id'   => $this->to_location_id,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'notes'        => $this->notes,
            'requested_by' => $this->requested_by,

            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),

            'completed_by' => $this->completed_by,
            'completed_at' => $this->completed_at?->toIso8601String(),

            'cancelled_by' => $this->cancelled_by,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // created_by, updated_by, deleted_at intentionally omitted

            'from_location' => $this->whenLoaded('fromLocation', fn() => $this->fromLocation
                ? ['id' => $this->fromLocation->id, 'location_code' => $this->fromLocation->location_code, 'name' => $this->fromLocation->name]
                : null
            ),

            'to_location' => $this->whenLoaded('toLocation', fn() => $this->toLocation
                ? ['id' => $this->toLocation->id, 'location_code' => $this->toLocation->location_code, 'name' => $this->toLocation->name]
                : null
            ),

            'requested_by_user' => $this->whenLoaded('requestedBy', fn() => $this->requestedBy
                ? ['id' => $this->requestedBy->id, 'name' => $this->requestedBy->name]
                : null
            ),

            'approved_by_user' => $this->whenLoaded('approvedBy', fn() => $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null
            ),

            'completed_by_user' => $this->whenLoaded('completedBy', fn() => $this->completedBy
                ? ['id' => $this->completedBy->id, 'name' => $this->completedBy->name]
                : null
            ),

            'cancelled_by_user' => $this->whenLoaded('cancelledBy', fn() => $this->cancelledBy
                ? ['id' => $this->cancelledBy->id, 'name' => $this->cancelledBy->name]
                : null
            ),

            'lines_count' => $this->whenCounted('lines'),
            'lines'       => InventoryTransferLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
