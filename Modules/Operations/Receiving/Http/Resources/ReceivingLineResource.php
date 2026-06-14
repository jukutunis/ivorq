<?php

namespace Modules\Operations\Receiving\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivingLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receiving_document_id' => $this->receiving_document_id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'inventory_item_id' => $this->inventory_item_id,
            'inventory_unit_id' => $this->inventory_unit_id,
            'destination_location_id' => $this->destination_location_id,
            'description' => $this->description,
            'received_quantity' => $this->received_quantity,
            'unit_cost' => $this->unit_cost,
            'line_total' => $this->line_total,
            'serial_number' => $this->serial_number,
            'imei' => $this->imei,
            'mac_address' => $this->mac_address,
            'lot_number' => $this->lot_number,
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'manufacture_date' => $this->manufacture_date?->format('Y-m-d'),
            'discrepancies' => ReceivingDiscrepancyResource::collection($this->whenLoaded('discrepancies')),
            'inspections' => ReceivingInspectionResource::collection($this->whenLoaded('inspections')),
        ];
    }
}
