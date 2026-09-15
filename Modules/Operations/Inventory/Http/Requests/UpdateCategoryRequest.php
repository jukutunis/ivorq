<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('inventory_categories', 'name')
                    ->ignore($categoryId)
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'parent_id' => [
                'sometimes', 'nullable', 'string', 'size:26', Rule::notIn([$categoryId]),
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
