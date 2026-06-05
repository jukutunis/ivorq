<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Models\AssetRequest;
use Shared\Services\CurrentPropertyService;

class StoreAssetRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AssetRequest::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'request_number' => ['required', 'string', 'max:20',
                "unique:asset_requests,request_number,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'title'          => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
            'work_order_id'  => ['nullable', 'string',
                Rule::exists('work_orders', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'priority'       => ['nullable', 'integer', 'min:1', 'max:4'],
            'required_by'    => ['nullable', 'date'],
            'department_id'  => ['nullable', 'string',
                Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],

            // Actor and status fields are server-controlled
            'status'       => ['prohibited'],
            'approved_by'  => ['prohibited'],
            'rejected_by'  => ['prohibited'],
            'fulfilled_by' => ['prohibited'],
        ];
    }
}
