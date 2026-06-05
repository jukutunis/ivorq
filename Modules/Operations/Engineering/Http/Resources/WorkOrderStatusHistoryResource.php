<?php

namespace Modules\Operations\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'property_id'   => $this->property_id,
            'work_order_id' => $this->work_order_id,
            'from_status'   => $this->from_status,
            'to_status'     => $this->to_status,
            'remarks'       => $this->remarks,
            'changed_by'    => $this->changed_by,
            'changed_at'    => $this->changed_at,
            'created_by'    => $this->created_by,
            'created_at'    => $this->created_at,

            'changed_by_user' => $this->whenLoaded('changedBy', fn() => $this->changedBy
                ? ['id' => $this->changedBy->id, 'name' => $this->changedBy->name]
                : null
            ),
        ];
    }
}
