<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Shared\Services\CurrentPropertyService;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryUnit::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'code' => [
                'required', 'string', 'max:255',
                Rule::unique('inventory_units', 'code')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'property_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
