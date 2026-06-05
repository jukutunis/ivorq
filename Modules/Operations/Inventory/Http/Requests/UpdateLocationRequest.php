<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Shared\Services\CurrentPropertyService;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $location = InventoryLocation::find($this->route('location'));

        return $location && $this->user()->can('update', $location);
    }

    public function rules(): array
    {
        $locationId = $this->route('location');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'location_code' => ['sometimes', 'string', 'max:20',
                "unique:inventory_locations,location_code,{$locationId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'name'          => ['sometimes', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'location_type' => ['sometimes', Rule::enum(LocationTypeEnum::class)],
            'is_active'     => ['sometimes', 'boolean'],

            // Server-managed
            'created_by'    => ['prohibited'],
            'updated_by'    => ['prohibited'],
        ];
    }
}
