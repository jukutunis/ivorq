<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'item_code'        => ['required', 'string', 'max:20',
                "unique:inventory_items,item_code,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'name'             => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'category_id'      => ['required', 'string', 'size:26',
                Rule::exists('inventory_categories', 'id')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'unit_id'          => ['required', 'string', 'size:26',
                Rule::exists('inventory_units', 'id')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'sku'              => ['nullable', 'string', 'max:100'],
            'barcode'          => ['nullable', 'string', 'max:100'],
            'min_stock'        => ['nullable', 'numeric', 'min:0'],
            'max_stock'        => ['nullable', 'numeric', 'min:0'],
            'reorder_point'    => ['nullable', 'numeric', 'min:0'],
            'reorder_quantity' => ['nullable', 'numeric', 'min:0'],
            'notes'            => ['nullable', 'string'],
            'is_active'        => ['sometimes', 'boolean'],

            // Server-managed — never from client
            'average_cost'     => ['prohibited'],
            'created_by'       => ['prohibited'],
            'updated_by'       => ['prohibited'],
        ];
    }
}
