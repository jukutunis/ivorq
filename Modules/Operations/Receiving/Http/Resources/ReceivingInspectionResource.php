<?php

namespace Modules\Operations\Receiving\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivingInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inspection_result' => $this->inspection_result,
            'temperature' => $this->temperature,
            'visual_quality_score' => $this->visual_quality_score,
            'notes' => $this->notes,
            'inspected_by' => $this->inspected_by,
            'inspected_at' => $this->inspected_at?->toIso8601String(),
        ];
    }
}
