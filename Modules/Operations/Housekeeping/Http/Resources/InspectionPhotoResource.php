<?php

namespace Modules\Operations\Housekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'property_id'   => $this->property_id,
            'inspection_id' => $this->inspection_id,
            'file_path'     => $this->file_path,
            'file_name'     => $this->file_name,
            'notes'         => $this->notes,
            'created_by'    => $this->created_by,
            'created_at'    => $this->created_at,
        ];
    }
}
