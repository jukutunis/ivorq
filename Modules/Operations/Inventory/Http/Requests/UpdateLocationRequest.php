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
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('inventory_locations', 'name')
                    ->ignore($locationId)
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['sometimes', 'required', Rule::enum(LocationTypeEnum::class)],
            'parent_id' => [
                'sometimes', 'nullable', 'string', 'size:26', Rule::notIn([$locationId]),
                Rule::exists('inventory_locations', 'id')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'property_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
