<?php

namespace Modules\Operations\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'request_no' => $this->request_no,
            'department_id' => $this->department_id,
            'requester_id' => $this->requester_id,
            'revision_number' => $this->revision_number,
            'current_revision_created_by' => $this->current_revision_created_by,
            'required_date' => $this->required_date?->format('Y-m-d'),
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'estimated_total' => $this->estimated_total,
            'status' => $this->status->value,
            'remarks' => $this->remarks,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'cancelled_by' => $this->cancelled_by,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'lines' => PurchaseRequestLineResource::collection($this->whenLoaded('lines')),
            'revision_history' => $this->whenLoaded('revisionSnapshots'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
