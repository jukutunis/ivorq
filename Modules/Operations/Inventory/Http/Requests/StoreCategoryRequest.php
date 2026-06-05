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
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'category_code' => ['required', 'string', 'max:20',
                "unique:inventory_categories,category_code,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'name'          => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'is_active'     => ['sometimes', 'boolean'],

            // Server-managed
            'created_by'    => ['prohibited'],
            'updated_by'    => ['prohibited'],
        ];
    }
}
