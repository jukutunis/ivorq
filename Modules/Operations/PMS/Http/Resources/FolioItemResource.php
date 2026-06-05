<?php

namespace Modules\Operations\PMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FolioItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'folio_id'    => $this->folio_id,

            'item_type' => [
                'value' => $this->item_type->value,
                'label' => $this->item_type->label(),
            ],

            'description' => $this->description,
            'quantity'    => (float) $this->quantity,
            'amount'      => (float) $this->amount,
            'is_void'     => $this->is_void,

            'posted_at' => $this->posted_at,
            'posted_by' => $this->posted_by,

            'created_at' => $this->created_at,

            // ── Nested relations ──────────────────────────────────────────────
            'folio' => $this->whenLoaded('folio', fn () => $this->folio
                ? [
                    'id'           => $this->folio->id,
                    'folio_number' => $this->folio->folio_number,
                ]
                : null
            ),

            'posted_by_user' => $this->whenLoaded('postedBy', fn () => $this->postedBy
                ? ['id' => $this->postedBy->id, 'name' => $this->postedBy->name]
                : null
            ),
        ];
    }
}
