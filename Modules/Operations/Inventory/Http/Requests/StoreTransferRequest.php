<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Shared\Services\CurrentPropertyService;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryTransfer::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'transfer_number'  => ['required', 'string', 'max:30',
                "unique:inventory_transfers,transfer_number,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'from_location_id' => ['required', 'string', 'size:26',
                Rule::exists('inventory_locations', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'to_location_id'   => ['required', 'string', 'size:26',
                Rule::exists('inventory_locations', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'notes'            => ['nullable', 'string', 'max:500'],

            // Lines — required at store time (BR-051)
            'lines'                    => ['required', 'array', 'min:1'],
            'lines.*.item_id'          => ['required', 'string', 'size:26',
                Rule::exists('inventory_items', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.quantity_requested' => ['required', 'numeric', 'min:0.001'],
            'lines.*.notes'              => ['nullable', 'string', 'max:500'],

            // Server-controlled lifecycle
            'status'       => ['prohibited'],
            'approved_by'  => ['prohibited'],
            'approved_at'  => ['prohibited'],
            'completed_by' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'requested_by' => ['prohibited'],
        ];
    }
}
