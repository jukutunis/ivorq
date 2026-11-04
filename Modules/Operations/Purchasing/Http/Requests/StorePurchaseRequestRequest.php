<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Shared\Services\CurrentPropertyService;

class StorePurchaseRequestRequest extends FormRequest
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
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'rejected_by' => ['prohibited'],
            'rejected_at' => ['prohibited'],
            'currency_code' => ['prohibited'],
            'exchange_rate' => ['prohibited'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'department_id' => [
                'required',
                'string',
                'size:26',
                Rule::exists('departments', 'id')->where(
                    fn ($query) => $query->where('property_id', $propertyId)->whereNull('deleted_at')
                ),
            ],
            'required_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_item_id' => [
                'required',
                'string',
                'size:26',
                Rule::exists('inventory_items', 'id')->where(
                    fn ($query) => $query->where('property_id', $propertyId)->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'lines.*.description' => ['prohibited'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => [
                'required',
                'string',
                'size:26',
                Rule::exists('inventory_units', 'id')->where(
                    fn ($query) => $query->where('property_id', $propertyId)->whereNull('deleted_at')
                ),
            ],
            'lines.*.estimated_unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string'],
        ];
    }
}
