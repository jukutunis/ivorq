<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Shared\Services\CurrentPropertyService;

class UpdateAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $adjustment = InventoryAdjustment::find($this->route('adjustment'));

        return $adjustment && $this->user()->can('update', $adjustment);
    }

    public function rules(): array
    {
        $adjustmentId = $this->route('adjustment');
        $propertyId   = app(CurrentPropertyService::class)->getId();

        return [
            'adjustment_number' => ['sometimes', 'string', 'max:30',
                "unique:inventory_adjustments,adjustment_number,{$adjustmentId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'location_id'       => ['sometimes', 'string', 'size:26',
                Rule::exists('inventory_locations', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'adjustment_type'   => ['sometimes', Rule::enum(AdjustmentTypeEnum::class)],
            'reason'            => ['sometimes', 'string', 'max:500'],

            // Lines optional on update
            'lines'                      => ['sometimes', 'array', 'min:1'],
            'lines.*.item_id'            => ['sometimes', 'string', 'size:26',
                Rule::exists('inventory_items', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.quantity_system'    => ['sometimes', 'numeric', 'min:0'],
            'lines.*.quantity_actual'    => ['sometimes', 'numeric', 'min:0'],
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
