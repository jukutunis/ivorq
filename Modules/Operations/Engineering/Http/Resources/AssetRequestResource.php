<?php

namespace Modules\Operations\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'property_id'    => $this->property_id,
            'request_number' => $this->request_number,
            'work_order_id'  => $this->work_order_id,
            'requester_id'   => $this->requester_id,
            'department_id'  => $this->department_id,
            'title'          => $this->title,
            'description'    => $this->description,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'priority' => [
                'value' => $this->priority->value,
                'label' => $this->priority->label(),
            ],
            'required_by'      => $this->required_by,
            'approved_by'      => $this->approved_by,
            'approved_at'      => $this->approved_at,
            'rejected_by'      => $this->rejected_by,
            'rejected_at'      => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'fulfilled_at'     => $this->fulfilled_at,
            'fulfilled_by'     => $this->fulfilled_by,
            'created_by'       => $this->created_by,
            'updated_by'       => $this->updated_by,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,

            'work_order' => $this->whenLoaded('workOrder', fn() => $this->workOrder
                ? [
                    'id'                => $this->workOrder->id,
                    'work_order_number' => $this->workOrder->work_order_number,
                    'title'             => $this->workOrder->title,
                  ]
                : null
            ),

            'requester' => $this->whenLoaded('requester', fn() => $this->requester
                ? ['id' => $this->requester->id, 'name' => $this->requester->name]
                : null
            ),

            'department' => $this->whenLoaded('department', fn() => $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name]
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

            'fulfilled_by_user' => $this->whenLoaded('fulfilledBy', fn() => $this->fulfilledBy
                ? ['id' => $this->fulfilledBy->id, 'name' => $this->fulfilledBy->name]
                : null
            ),
        ];
    }
}
