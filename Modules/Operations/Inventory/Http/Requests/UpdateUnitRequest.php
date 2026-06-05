<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Shared\Services\CurrentPropertyService;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unit = InventoryUnit::find($this->route('unit'));

        return $unit && $this->user()->can('update', $unit);
    }

    public function rules(): array
    {
        $unitId     = $this->route('unit');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'unit_code'    => ['sometimes', 'string', 'max:20',
                "unique:inventory_units,unit_code,{$unitId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'name'         => ['sometimes', 'string', 'max:255'],
            'abbreviation' => ['sometimes', 'string', 'max:10'],
            'is_active'    => ['sometimes', 'boolean'],

            // Server-managed
            'created_by'   => ['prohibited'],
            'updated_by'   => ['prohibited'],
        ];
    }
}
