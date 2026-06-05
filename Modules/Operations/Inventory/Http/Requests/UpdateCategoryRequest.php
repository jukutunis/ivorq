<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Shared\Services\CurrentPropertyService;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = InventoryCategory::find($this->route('category'));

        return $category && $this->user()->can('update', $category);
    }

    public function rules(): array
    {
        $categoryId = $this->route('category');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'category_code' => ['sometimes', 'string', 'max:20',
                "unique:inventory_categories,category_code,{$categoryId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'name'          => ['sometimes', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'is_active'     => ['sometimes', 'boolean'],

            // Server-managed
            'created_by'    => ['prohibited'],
            'updated_by'    => ['prohibited'],
        ];
    }
}
