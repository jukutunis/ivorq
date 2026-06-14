<?php

namespace Modules\Operations\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RFQResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rfq_number' => $this->rfq_number,
            'title' => $this->title,
            'status' => $this->status,
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'purchase_request' => new PurchaseRequestResource($this->whenLoaded('purchaseRequest')),
            'invited_vendors' => VendorResource::collection($this->whenLoaded('vendors')),
            'quotations' => QuotationResource::collection($this->whenLoaded('quotations')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
