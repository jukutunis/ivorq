<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Shared\Services\CurrentPropertyService;

class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryAdjustment::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'adjustment_number' => ['required', 'string', 'max:30',
                "unique:inventory_adjustments,adjustment_number,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'location_id'       => ['required', 'string', 'size:26',
                Rule::exists('inventory_locations', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'adjustment_type'   => ['required', Rule::enum(AdjustmentTypeEnum::class)],
            'reason'            => ['required', 'string', 'max:500'],

            // Lines — required at store (BR-061)
            'lines'                      => ['required', 'array', 'min:1'],
            'lines.*.item_id'            => ['required', 'string', 'size:26',
                Rule::exists('inventory_items', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.quantity_system'    => ['required', 'numeric', 'min:0'],
            'lines.*.quantity_actual'    => ['required', 'numeric', 'min:0'],
            'lines.*.unit_cost'          => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes'              => ['nullable', 'string', 'max:500'],

            // Server-computed
            'lines.*.quantity_variance'  => ['prohibited'],

            // Server-controlled lifecycle
            'status'           => ['prohibited'],
            'submitted_by'     => ['prohibited'],
            'submitted_at'     => ['prohibited'],
            'approved_by'      => ['prohibited'],
            'approved_at'      => ['prohibited'],
            'rejected_by'      => ['prohibited'],
            'rejected_at'      => ['prohibited'],
            'rejection_reason' => ['prohibited'],
        ];
    }
}
