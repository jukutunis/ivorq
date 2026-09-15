<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Enums\InventoryCriticalityEnum;
use Modules\Operations\Inventory\Enums\InventoryTypeEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Shared\Services\CurrentPropertyService;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = InventoryItem::find($this->route('item'));

        return $item && $this->user()->can('update', $item);
    }

    public function rules(): array
    {
        $itemId = $this->route('item');
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'sku' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('inventory_items', 'sku')
                    ->ignore($itemId)
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => [
                'sometimes', 'required', 'string', 'size:26',
                Rule::exists('inventory_categories', 'id')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'inventory_type' => ['sometimes', 'required', Rule::enum(InventoryTypeEnum::class)],
            'criticality' => ['sometimes', 'required', Rule::enum(InventoryCriticalityEnum::class)],
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
