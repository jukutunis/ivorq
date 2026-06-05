<?php

namespace Modules\Operations\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreventiveMaintenanceTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'property_id'               => $this->property_id,
            'preventive_maintenance_id' => $this->preventive_maintenance_id,
            'work_order_id'             => $this->work_order_id,
            'scheduled_date'            => $this->scheduled_date,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'completed_at' => $this->completed_at,
            'completed_by' => $this->completed_by,
            'remarks'      => $this->remarks,
            'created_by'   => $this->created_by,
            'updated_by'   => $this->updated_by,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,

            'preventive_maintenance' => $this->whenLoaded('preventiveMaintenance', fn() => $this->preventiveMaintenance
                ? [
                    'id'      => $this->preventiveMaintenance->id,
                    'pm_code' => $this->preventiveMaintenance->pm_code,
                    'title'   => $this->preventiveMaintenance->title,
                  ]
                : null
            ),

            'work_order' => $this->whenLoaded('workOrder', fn() => $this->workOrder
                ? [
                    'id'                => $this->workOrder->id,
                    'work_order_number' => $this->workOrder->work_order_number,
                    'title'             => $this->workOrder->title,
                  ]
                : null
            ),

            'completed_by_user' => $this->whenLoaded('completedBy', fn() => $this->completedBy
                ? ['id' => $this->completedBy->id, 'name' => $this->completedBy->name]
                : null
            ),
        ];
    }
}
