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
            'request_no' => $this->request_no,
            'department_id' => $this->department_id,
            'requester_id' => $this->requester_id,
            'required_date' => $this->required_date?->format('Y-m-d'),
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'estimated_total' => $this->estimated_total,
            'status' => $this->status->value,
            'remarks' => $this->remarks,
            'lines' => PurchaseRequestLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
