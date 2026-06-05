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
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'location_code' => ['required', 'string', 'max:20',
                "unique:inventory_locations,location_code,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'name'          => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'location_type' => ['required', Rule::enum(LocationTypeEnum::class)],
            'is_active'     => ['sometimes', 'boolean'],

            // Server-managed
            'created_by'    => ['prohibited'],
            'updated_by'    => ['prohibited'],
        ];
    }
}
