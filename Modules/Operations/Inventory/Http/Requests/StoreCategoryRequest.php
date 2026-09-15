<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Shared\Services\CurrentPropertyService;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryCategory::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('inventory_categories', 'name')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'parent_id' => [
                'nullable', 'string', 'size:26',
                Rule::exists('inventory_categories', 'id')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'property_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
