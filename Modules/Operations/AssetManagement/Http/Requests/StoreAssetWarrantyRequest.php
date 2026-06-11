<?php

namespace Modules\Operations\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('asset.warranty.manage');
    }

    public function rules(): array
    {
        return [
            'property_id' => 'required|string',
            'asset_id' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'vendor_id' => 'nullable|string',
            'coverage_type' => 'nullable|string',
            'terms' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }
}
