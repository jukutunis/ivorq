<?php

namespace Modules\Operations\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('asset.create');
    }

    public function rules(): array
    {
        return [
            'property_id' => 'required|string',
            'asset_category_id' => 'required|string',
            'asset_type_id' => 'required|string',
            'name' => 'required|string',
            'status' => 'required|string',
            'condition' => 'required|string',
            'criticality' => 'required|string',
            'department_id' => 'nullable|string',
            'location_id' => 'nullable|string',
            'asset_group_id' => 'nullable|string',
            'asset_number' => 'nullable|string',
            'qr_uri' => 'nullable|string',
            'serial_number' => 'nullable|string',
            'model_number' => 'nullable|string',
            'manufacturer' => 'nullable|string',
            'purchase_date' => 'nullable|date',
            'installation_date' => 'nullable|date',
            'commissioning_date' => 'nullable|date',
            'disposal_date' => 'nullable|date',
        ];
    }
}
