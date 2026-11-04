<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Shared\Services\CurrentPropertyService;

class UpdatePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'property_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'request_no' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'status' => ['prohibited'],
            'currency_code' => ['prohibited'],
            'exchange_rate' => ['prohibited'],
            'department_id' => [
                'sometimes',
                'string',
                'size:26',
                Rule::exists('departments', 'id')->where(
                    fn ($query) => $query->where('property_id', $propertyId)->whereNull('deleted_at')
                ),
            ],
            'required_date' => ['sometimes', 'date'],
            'remarks' => ['nullable', 'string'],

            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['nullable', 'string', 'size:26'],
            'lines.*.inventory_item_id' => [
                'required_with:lines',
                'string',
                'size:26',
                Rule::exists('inventory_items', 'id')->where(
                    fn ($query) => $query->where('property_id', $propertyId)->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'lines.*.description' => ['prohibited'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.unit_id' => [
                'required_with:lines',
                'string',
                'size:26',
                Rule::exists('inventory_units', 'id')->where(
                    fn ($query) => $query->where('property_id', $propertyId)->whereNull('deleted_at')
                ),
            ],
            'lines.*.estimated_unit_cost' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string'],
        ];
    }
}
