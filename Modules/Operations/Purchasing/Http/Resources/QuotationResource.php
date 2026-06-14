<?php

namespace Modules\Operations\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quotation_number' => $this->quotation_number,
            'vendor' => new VendorResource($this->whenLoaded('vendor')),
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'lead_time_days' => $this->lead_time_days,
            'is_winner' => $this->is_winner,
            'lines' => QuotationLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
