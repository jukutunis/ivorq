<?php

namespace Modules\Operations\Housekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChecklistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'property_id'  => $this->property_id,
            'checklist_id' => $this->checklist_id,
            'item_text'    => $this->item_text,
            'sort_order'   => $this->sort_order,
            'is_required'  => $this->is_required,
            'created_by'   => $this->created_by,
            'updated_by'   => $this->updated_by,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
