<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        $unitId = $this->route('unit');
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('inventory_units', 'code')
                    ->ignore($unitId)
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'property_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
