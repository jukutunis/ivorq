<?php

namespace Modules\Operations\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_category_id' => $this->vendor_category_id,
            'category' => new VendorCategoryResource($this->whenLoaded('category')),
            'vendor_code' => $this->vendor_code,
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'default_currency_code' => $this->default_currency_code,
            'is_active' => $this->is_active,
            'is_approved' => $this->is_approved,
            'performance_score' => $this->performance_score,
            'contacts' => VendorContactResource::collection($this->whenLoaded('contacts')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
