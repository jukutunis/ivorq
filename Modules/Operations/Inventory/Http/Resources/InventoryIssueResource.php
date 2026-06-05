<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'issue_number'   => $this->issue_number,
            'department_id'  => $this->department_id,
            'issued_to_type' => $this->issued_to_type,
            'issued_to_id'   => $this->issued_to_id,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'issued_at'    => $this->issued_at?->toIso8601String(),
            'remarks'      => $this->remarks,

            'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toIso8601String(),

            'cancelled_by' => $this->cancelled_by,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // created_by, updated_by, deleted_at intentionally omitted

            'department' => $this->whenLoaded('department', fn() => $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name]
                : null
            ),

            'posted_by_user' => $this->whenLoaded('postedBy', fn() => $this->postedBy
                ? ['id' => $this->postedBy->id, 'name' => $this->postedBy->name]
                : null
            ),

            'cancelled_by_user' => $this->whenLoaded('cancelledBy', fn() => $this->cancelledBy
                ? ['id' => $this->cancelledBy->id, 'name' => $this->cancelledBy->name]
                : null
            ),

            'lines_count' => $this->whenCounted('lines'),
            'lines'       => InventoryIssueLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
