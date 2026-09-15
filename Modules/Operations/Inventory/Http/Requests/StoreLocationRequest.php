<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Shared\Services\CurrentPropertyService;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryLocation::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('inventory_locations', 'name')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', Rule::enum(LocationTypeEnum::class)],
            'parent_id' => [
                'nullable', 'string', 'size:26',
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
