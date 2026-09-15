<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Enums\InventoryCriticalityEnum;
use Modules\Operations\Inventory\Enums\InventoryTypeEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Shared\Services\CurrentPropertyService;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryItem::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'sku' => [
                'required', 'string', 'max:255',
                Rule::unique('inventory_items', 'sku')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => [
                'required', 'string', 'size:26',
                Rule::exists('inventory_categories', 'id')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'inventory_type' => ['required', Rule::enum(InventoryTypeEnum::class)],
            'criticality' => ['sometimes', Rule::enum(InventoryCriticalityEnum::class)],
            'is_batch_tracked' => ['sometimes', 'boolean'],
            'is_expiry_tracked' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'reorder_point' => ['sometimes', 'numeric', 'min:0'],
            'property_id' => ['prohibited'],
            'weighted_average_cost' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
