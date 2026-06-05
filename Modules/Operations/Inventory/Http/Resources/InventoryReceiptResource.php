<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'receipt_number'     => $this->receipt_number,
            'supplier_name'      => $this->supplier_name,
            'external_reference' => $this->external_reference,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'received_at'  => $this->received_at?->toIso8601String(),
            'remarks'      => $this->remarks,

            'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toIso8601String(),

            'cancelled_by' => $this->cancelled_by,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // created_by, updated_by, deleted_at intentionally omitted

            'posted_by_user' => $this->whenLoaded('postedBy', fn() => $this->postedBy
                ? ['id' => $this->postedBy->id, 'name' => $this->postedBy->name]
                : null
            ),

            'cancelled_by_user' => $this->whenLoaded('cancelledBy', fn() => $this->cancelledBy
                ? ['id' => $this->cancelledBy->id, 'name' => $this->cancelledBy->name]
                : null
            ),

            'lines_count' => $this->whenCounted('lines'),
            'lines'       => InventoryReceiptLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
