<?php

namespace Modules\Operations\Receiving\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivingDiscrepancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'discrepancy_type' => $this->discrepancy_type,
            'reported_quantity' => $this->reported_quantity,
            'reason' => $this->reason,
            'status' => $this->status,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
        ];
    }
}
