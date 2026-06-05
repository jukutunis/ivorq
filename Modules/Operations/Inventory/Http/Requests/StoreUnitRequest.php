<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'unit_code'    => ['required', 'string', 'max:20',
                "unique:inventory_units,unit_code,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'name'         => ['required', 'string', 'max:255'],
            'abbreviation' => ['required', 'string', 'max:10'],
            'is_active'    => ['sometimes', 'boolean'],

            // Server-managed
            'created_by'   => ['prohibited'],
            'updated_by'   => ['prohibited'],
        ];
    }
}
